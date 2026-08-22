import { useLayoutEffect } from 'react';
import {
    defineCompatibilityCatalog,
    registerCompatibilityCatalog,
    resolveCompatibilityValue,
    subscribeToCompatibilityCatalogs,
} from '@/i18n/catalog';
import { loadBundledCompatibilityCatalogs } from '@/i18n/catalog-loader';
import { commonMessages } from '@/i18n/messages/common';
import { englishExactMessages } from '@/i18n/translator';
import type { LocaleCode } from '@/i18n/types';

const translatedAttributes = [
    'alt',
    'aria-description',
    'aria-label',
    'placeholder',
    'title',
] as const;
const bridgeControlAttributes = [
    'data-no-translate',
    'data-translate',
    'data-user-content',
    'translate',
] as const;
const optOutSelector =
    '[data-no-translate], [data-user-content], [translate="no"]';
const excludedTextContainer =
    'script, style, template, noscript, code, pre, [contenteditable="true"], [contenteditable=""]';

const originalTexts = new WeakMap<Text, string>();
const appliedTexts = new WeakMap<Text, string>();
const originalAttributes = new WeakMap<Element, Map<string, string>>();
const appliedAttributes = new WeakMap<Element, Map<string, string>>();

registerCompatibilityCatalog(
    defineCompatibilityCatalog({
        id: 'foundation',
        exact: {
            ...englishExactMessages(commonMessages),
            إغلاق: 'Close',
            التالي: 'Next',
            السابق: 'Previous',
            'جار التحميل': 'Loading',
        },
    }),
);

function isOptedOut(element: Element | null): boolean {
    return Boolean(element?.closest(optOutSelector));
}

function isProtectedContent(
    element: Element,
    value: string,
    protectedContent: ReadonlySet<string>,
): boolean {
    return (
        !element.closest('[data-translate]') &&
        protectedContent.has(value.trim())
    );
}

function resolveDocumentTitle(value: string): string | null {
    const separator = ' - ';
    const separatorIndex = value.lastIndexOf(separator);

    if (separatorIndex <= 0) {
        return null;
    }

    const pageTitle = value.slice(0, separatorIndex);
    const translatedTitle = resolveCompatibilityValue(pageTitle, 'text');

    return translatedTitle === null
        ? null
        : `${translatedTitle}${value.slice(separatorIndex)}`;
}

function translateText(text: Text, protectedContent: ReadonlySet<string>) {
    const parent = text.parentElement;

    if (
        !parent ||
        isOptedOut(parent) ||
        parent.closest(excludedTextContainer)
    ) {
        return;
    }

    const current = text.data;

    if (isProtectedContent(parent, current, protectedContent)) {
        return;
    }

    if (appliedTexts.get(text) === current) {
        return;
    }

    const translated =
        resolveCompatibilityValue(current, 'text') ??
        (parent.tagName === 'TITLE' ? resolveDocumentTitle(current) : null);

    if (translated === null || translated === current) {
        originalTexts.delete(text);
        appliedTexts.delete(text);

        return;
    }

    originalTexts.set(text, current);
    appliedTexts.set(text, translated);
    text.data = translated;
}

function elementAttributeMap(
    storage: WeakMap<Element, Map<string, string>>,
    element: Element,
): Map<string, string> {
    const existing = storage.get(element);

    if (existing) {
        return existing;
    }

    const created = new Map<string, string>();
    storage.set(element, created);

    return created;
}

function translateDirection(element: Element) {
    const current = element.getAttribute('dir');

    if (current !== 'rtl') {
        return;
    }

    elementAttributeMap(originalAttributes, element).set('dir', current);
    elementAttributeMap(appliedAttributes, element).set('dir', 'ltr');
    element.setAttribute('dir', 'ltr');
}

function translateAttributes(
    element: Element,
    protectedContent: ReadonlySet<string>,
) {
    if (isOptedOut(element)) {
        return;
    }

    translatedAttributes.forEach((attribute) => {
        const current = element.getAttribute(attribute);

        if (current === null) {
            return;
        }

        if (isProtectedContent(element, current, protectedContent)) {
            return;
        }

        const applied = appliedAttributes.get(element)?.get(attribute);

        if (applied === current) {
            return;
        }

        const translated = resolveCompatibilityValue(current, 'attribute');

        if (translated === null || translated === current) {
            originalAttributes.get(element)?.delete(attribute);
            appliedAttributes.get(element)?.delete(attribute);

            return;
        }

        elementAttributeMap(originalAttributes, element).set(
            attribute,
            current,
        );
        elementAttributeMap(appliedAttributes, element).set(
            attribute,
            translated,
        );
        element.setAttribute(attribute, translated);
    });
}

function translateTree(root: Node, protectedContent: ReadonlySet<string>) {
    const pending: Node[] = [root];

    while (pending.length > 0) {
        const node = pending.pop();

        if (!node) {
            continue;
        }

        if (node instanceof Text) {
            translateText(node, protectedContent);

            continue;
        }

        if (node instanceof Element) {
            translateDirection(node);

            if (!isOptedOut(node)) {
                translateAttributes(node, protectedContent);
            }

            if (node.matches(excludedTextContainer)) {
                continue;
            }
        }

        for (let index = node.childNodes.length - 1; index >= 0; index--) {
            const child = node.childNodes.item(index);

            if (child) {
                pending.push(child);
            }
        }
    }
}

function restoreText(text: Text) {
    const original = originalTexts.get(text);
    const applied = appliedTexts.get(text);

    if (original !== undefined && applied === text.data) {
        text.data = original;
    }

    originalTexts.delete(text);
    appliedTexts.delete(text);
}

function restoreElementAttributes(element: Element) {
    const originals = originalAttributes.get(element);
    const applied = appliedAttributes.get(element);

    originals?.forEach((original, attribute) => {
        if (element.getAttribute(attribute) === applied?.get(attribute)) {
            element.setAttribute(attribute, original);
        }
    });
    originalAttributes.delete(element);
    appliedAttributes.delete(element);
}

function restoreTree(root: Node) {
    const pending: Node[] = [root];

    while (pending.length > 0) {
        const node = pending.pop();

        if (!node) {
            continue;
        }

        if (node instanceof Text) {
            restoreText(node);
        } else if (node instanceof Element) {
            restoreElementAttributes(node);
        }

        for (let index = node.childNodes.length - 1; index >= 0; index--) {
            const child = node.childNodes.item(index);

            if (child) {
                pending.push(child);
            }
        }
    }
}

function observeEnglishContent(
    root: HTMLElement,
    protectedContent: ReadonlySet<string>,
): MutationObserver {
    const queuedRoots = new Set<Node>();
    let frame: number | null = null;

    const flush = () => {
        frame = null;
        queuedRoots.forEach((node) => {
            if (node.isConnected) {
                translateTree(node, protectedContent);
            }
        });
        queuedRoots.clear();
    };

    const queue = (node: Node) => {
        queuedRoots.add(node);

        if (frame === null) {
            frame = window.requestAnimationFrame(flush);
        }
    };

    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.type === 'childList') {
                mutation.addedNodes.forEach(queue);
            } else if (
                mutation.type === 'attributes' &&
                bridgeControlAttributes.includes(
                    mutation.attributeName as (typeof bridgeControlAttributes)[number],
                )
            ) {
                restoreTree(mutation.target);

                if (
                    mutation.target instanceof Element &&
                    !isOptedOut(mutation.target)
                ) {
                    queue(mutation.target);
                }
            } else {
                queue(mutation.target);
            }
        });
    });

    observer.observe(root, {
        attributes: true,
        attributeFilter: [
            ...translatedAttributes,
            ...bridgeControlAttributes,
            'dir',
        ],
        characterData: true,
        childList: true,
        subtree: true,
    });

    const disconnect = observer.disconnect.bind(observer);
    observer.disconnect = () => {
        disconnect();

        if (frame !== null) {
            window.cancelAnimationFrame(frame);
        }

        queuedRoots.clear();
    };

    return observer;
}

export function useCompatibilityBridge(
    locale: LocaleCode,
    protectedContent: ReadonlySet<string>,
) {
    useLayoutEffect(() => {
        const root = document.documentElement;

        if (locale === 'ar') {
            restoreTree(root);

            return;
        }

        restoreTree(root);
        translateTree(root, protectedContent);
        const observer = observeEnglishContent(root, protectedContent);
        const unsubscribe = subscribeToCompatibilityCatalogs(() =>
            translateTree(root, protectedContent),
        );
        let active = true;

        void loadBundledCompatibilityCatalogs().catch((error: unknown) => {
            if (active && import.meta.env.DEV) {
                console.error('Could not load compatibility catalogs.', error);
            }
        });

        return () => {
            active = false;
            unsubscribe();
            observer.disconnect();
        };
    }, [locale, protectedContent]);
}
