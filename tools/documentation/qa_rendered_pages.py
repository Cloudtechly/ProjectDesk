from __future__ import annotations

import json
import re
from pathlib import Path

from PIL import Image, ImageChops, ImageDraw, ImageFont, ImageStat


ROOT = Path(__file__).resolve().parents[2]
RENDERS = ROOT / "tmp" / "development-v2-qa"
CONTACTS = ROOT / "tmp" / "development-v2-contact-sheets"
REPORT = ROOT / "tmp" / "development-v2-page-qa.json"


def page_number(path: Path) -> int:
    match = re.search(r"(\d+)$", path.stem)
    return int(match.group(1)) if match else 0


def analyze(path: Path) -> dict:
    image = Image.open(path).convert("RGB")
    white = Image.new("RGB", image.size, "white")
    diff = ImageChops.difference(image, white).convert("L")
    threshold = diff.point(lambda value: 255 if value > 12 else 0)
    bbox = threshold.getbbox()
    pixels = threshold.histogram()[255]
    ratio = pixels / (image.width * image.height)
    margin = 18
    edge_pixels = 0
    for crop_box in [
        (0, 0, image.width, margin),
        (0, image.height - margin, image.width, image.height),
        (0, 0, margin, image.height),
        (image.width - margin, 0, image.width, image.height),
    ]:
        edge_pixels += threshold.crop(crop_box).histogram()[255]
    return {
        "file": path.name,
        "width": image.width,
        "height": image.height,
        "content_ratio": round(ratio, 5),
        "content_bbox": bbox,
        "edge_pixels": edge_pixels,
        "blank_suspected": ratio < 0.0015,
        "edge_collision_suspected": edge_pixels > 70,
    }


def font(size: int) -> ImageFont.ImageFont:
    candidate = Path("C:/Windows/Fonts/arial.ttf")
    return ImageFont.truetype(str(candidate), size) if candidate.exists() else ImageFont.load_default()


def make_contact_sheet(paths: list[Path], out_path: Path) -> None:
    opened = [Image.open(path).convert("RGB") for path in paths]
    cell_w = max(image.width for image in opened)
    cell_h = max(image.height for image in opened)
    label_h = 42
    sheet = Image.new("RGB", (cell_w * 2 + 24, (cell_h + label_h) * 2 + 24), "#dce6e8")
    draw = ImageDraw.Draw(sheet)
    label_font = font(24)
    for index, (path, image) in enumerate(zip(paths, opened)):
        row, col = divmod(index, 2)
        x = 8 + col * (cell_w + 8)
        y = 8 + row * (cell_h + label_h + 8)
        sheet.paste(image, (x, y + label_h))
        draw.rectangle((x, y, x + cell_w, y + label_h), fill="#243442")
        draw.text((x + 10, y + 7), f"{path.parent.name} | {path.stem}", fill="white", font=label_font)
    out_path.parent.mkdir(parents=True, exist_ok=True)
    sheet.save(out_path, optimize=True)


def main() -> None:
    CONTACTS.mkdir(parents=True, exist_ok=True)
    report = {"documents": {}, "summary": {"pages": 0, "blank_suspected": 0, "edge_collision_suspected": 0}}
    for document_dir in sorted(path for path in RENDERS.iterdir() if path.is_dir()):
        pages = sorted(document_dir.glob("page-*.png"), key=page_number)
        analyses = [analyze(path) for path in pages]
        report["documents"][document_dir.name] = analyses
        report["summary"]["pages"] += len(analyses)
        report["summary"]["blank_suspected"] += sum(item["blank_suspected"] for item in analyses)
        report["summary"]["edge_collision_suspected"] += sum(item["edge_collision_suspected"] for item in analyses)
        for start in range(0, len(pages), 4):
            group = pages[start : start + 4]
            end = page_number(group[-1])
            out_name = f"{document_dir.name}__pages-{page_number(group[0]):03d}-{end:03d}.png"
            make_contact_sheet(group, CONTACTS / out_name)
    REPORT.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps(report["summary"], ensure_ascii=False))
    print(f"contact_sheets={len(list(CONTACTS.glob('*.png')))}")


if __name__ == "__main__":
    main()
