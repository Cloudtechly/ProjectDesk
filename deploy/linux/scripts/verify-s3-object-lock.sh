#!/usr/bin/env bash
set -euo pipefail

: "${S3_BUCKET:?S3_BUCKET must be configured}"
: "${S3_OBJECT_KEY:?S3_OBJECT_KEY must identify the uploaded .pdesk object}"
: "${S3_MINIMUM_RETENTION_DAYS:=35}"

if [[ ! "${S3_MINIMUM_RETENTION_DAYS}" =~ ^[0-9]+$ || "${S3_MINIMUM_RETENTION_DAYS}" -lt 35 ]]; then
    echo 'S3_MINIMUM_RETENTION_DAYS must be an integer of at least 35.' >&2
    exit 2
fi

aws_arguments=()
if [[ -n "${S3_ENDPOINT_URL:-}" ]]; then
    aws_arguments+=(--endpoint-url "${S3_ENDPOINT_URL}")
fi

object_lock_json="$(aws "${aws_arguments[@]}" s3api get-object-lock-configuration --bucket "${S3_BUCKET}" --output json)"
versioning_json="$(aws "${aws_arguments[@]}" s3api get-bucket-versioning --bucket "${S3_BUCKET}" --output json)"
encryption_json="$(aws "${aws_arguments[@]}" s3api get-bucket-encryption --bucket "${S3_BUCKET}" --output json)"
public_access_json="$(aws "${aws_arguments[@]}" s3api get-public-access-block --bucket "${S3_BUCKET}" --output json)"
object_json="$(aws "${aws_arguments[@]}" s3api head-object --bucket "${S3_BUCKET}" --key "${S3_OBJECT_KEY}" --output json)"

jq -e --argjson minimum "${S3_MINIMUM_RETENTION_DAYS}" '
    .ObjectLockConfiguration.ObjectLockEnabled == "Enabled"
    and .ObjectLockConfiguration.Rule.DefaultRetention.Mode == "COMPLIANCE"
    and (
        (.ObjectLockConfiguration.Rule.DefaultRetention.Days // 0) >= $minimum
        or (.ObjectLockConfiguration.Rule.DefaultRetention.Years // 0) >= 1
    )
' <<< "${object_lock_json}" >/dev/null

jq -e '.Status == "Enabled"' <<< "${versioning_json}" >/dev/null
jq -e '
    [.ServerSideEncryptionConfiguration.Rules[].ApplyServerSideEncryptionByDefault.SSEAlgorithm]
    | all(. == "AES256" or . == "aws:kms")
' <<< "${encryption_json}" >/dev/null
jq -e '
    .PublicAccessBlockConfiguration
    | .BlockPublicAcls and .IgnorePublicAcls and .BlockPublicPolicy and .RestrictPublicBuckets
' <<< "${public_access_json}" >/dev/null
jq -e '
    (.VersionId | type == "string" and length > 0 and . != "null")
    and .ObjectLockMode == "COMPLIANCE"
    and (.ObjectLockRetainUntilDate | type == "string" and length > 0)
    and (.LastModified | type == "string" and length > 0)
    and (.ServerSideEncryption == "AES256" or .ServerSideEncryption == "aws:kms")
' <<< "${object_json}" >/dev/null

created_epoch="$(date --date "$(jq -r '.LastModified' <<< "${object_json}")" +%s)"
retention_epoch="$(date --date "$(jq -r '.ObjectLockRetainUntilDate' <<< "${object_json}")" +%s)"
minimum_retention_seconds=$((S3_MINIMUM_RETENTION_DAYS * 86400))
if ((retention_epoch - created_epoch < minimum_retention_seconds)); then
    echo "The uploaded object retention is shorter than ${S3_MINIMUM_RETENTION_DAYS} days" >&2
    exit 4
fi

if [[ "${S3_REQUIRE_ACCOUNT_PUBLIC_BLOCK:-false}" == 'true' ]]; then
    : "${AWS_ACCOUNT_ID:?AWS_ACCOUNT_ID is required for account-level public access verification}"
    account_block_json="$(aws "${aws_arguments[@]}" s3control get-public-access-block --account-id "${AWS_ACCOUNT_ID}" --output json)"
    jq -e '
        .PublicAccessBlockConfiguration
        | .BlockPublicAcls and .IgnorePublicAcls and .BlockPublicPolicy and .RestrictPublicBuckets
    ' <<< "${account_block_json}" >/dev/null
fi

jq -n \
    --arg checked_at "$(date --iso-8601=seconds)" \
    --arg bucket "${S3_BUCKET}" \
    --arg object_key "${S3_OBJECT_KEY}" \
    --arg object_version_id "$(jq -r '.VersionId' <<< "${object_json}")" \
    --arg object_created_at "$(jq -r '.LastModified' <<< "${object_json}")" \
    --arg retention_until "$(jq -r '.ObjectLockRetainUntilDate' <<< "${object_json}")" \
    --argjson retention_days "${S3_MINIMUM_RETENTION_DAYS}" \
    '{
        checked_at: $checked_at,
        bucket: $bucket,
        object_key: $object_key,
        object_version_id: $object_version_id,
        object_created_at: $object_created_at,
        retention_until: $retention_until,
        object_lock_enabled: true,
        object_lock_mode: "COMPLIANCE",
        retention_days: $retention_days,
        versioning_enabled: true,
        server_side_encryption_enabled: true,
        public_access_blocked: true
    }'
