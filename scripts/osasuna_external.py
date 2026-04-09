import json
import os
import re
from urllib.parse import urljoin

import pymysql
from bs4 import BeautifulSoup
from playwright.sync_api import sync_playwright

PRODUCTS = [
    {
        "url": "https://tienda.osasuna.es/es/osasuna-2025-26-adults-home-match-jersey.html",
        "kit_type": "1",
        "audience": "hombre",
    },
    {
        "url": "https://tienda.osasuna.es/es/osasuna-2025-26-home-match-shirt-for-women.html",
        "kit_type": "1",
        "audience": "mujer",
    },
    {
        "url": "https://tienda.osasuna.es/es/osasuna-2025-26-junior-home-match-jersey.html",
        "kit_type": "1",
        "audience": "nino",
    },
    {
        "url": "https://tienda.osasuna.es/es/osasuna-2025-26-adults-away-match-jersey.html",
        "kit_type": "2",
        "audience": "hombre",
    },
    {
        "url": "https://tienda.osasuna.es/es/osasuna-2025-26-junior-away-match-jersey.html",
        "kit_type": "2",
        "audience": "nino",
    },
    {
        "url": "https://tienda.osasuna.es/es/osasuna-2025-26-adults-third-match-jersey.html",
        "kit_type": "3",
        "audience": "hombre",
    },
    {
        "url": "https://tienda.osasuna.es/es/osasuna-2025-26-junior-third-match-jersey.html",
        "kit_type": "3",
        "audience": "nino",
    },
]

USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/124.0.0.0 Safari/537.36"
)

CLUB_NAME = "Osasuna"


def clean_text(text: str) -> str:
    return re.sub(r"\s+", " ", (text or "")).strip()


def clean_price_to_float(value: str):
    value = clean_text(value).replace("€", "").replace("EUR", "").replace(" ", "")
    value = re.sub(r"[^0-9,.\-]", "", value)

    if "," in value and "." in value:
        value = value.replace(".", "").replace(",", ".")
    elif "," in value:
        value = value.replace(",", ".")

    try:
        return round(float(value), 2)
    except Exception:
        return None


def extract_title(soup: BeautifulSoup) -> str:
    h1 = soup.select_one("h1")
    if h1:
        return clean_text(h1.get_text(" ", strip=True))

    og = soup.select_one('meta[property="og:title"]')
    if og and og.get("content"):
        return clean_text(og["content"])

    if soup.title and soup.title.string:
        return clean_text(soup.title.string)

    return ""


def extract_image(soup: BeautifulSoup, page_url: str) -> str:
    og = soup.select_one('meta[property="og:image"]')
    if og and og.get("content"):
        return urljoin(page_url, og["content"])
    return ""


def extract_prices(html: str):
    prices = []

    for raw in re.findall(r"(?:€\s*\d{1,4}(?:[.,]\d{2})|\d{1,4}(?:[.,]\d{2})\s*€)", html):
        price = clean_price_to_float(raw)
        if price is not None and 10 <= price <= 300:
            prices.append(price)

    prices = sorted(set(prices))

    if len(prices) == 1:
        return prices[0], None, 0

    if len(prices) >= 2:
        low = min(prices)
        high = max(prices)
        if low < high:
            return high, low, 1
        return high, None, 0

    return None, None, 0


def source_hash(product_url: str, kit_type: str, audience: str) -> str:
    import hashlib
    raw = f"{CLUB_NAME}|{product_url}|{kit_type}|{audience}|camiseta|fan"
    return hashlib.md5(raw.encode("utf-8")).hexdigest()


def scrape_products():
    items = []

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        page = browser.new_page(
            user_agent=USER_AGENT,
            viewport={"width": 1400, "height": 1200},
            locale="es-ES",
        )

        for product in PRODUCTS:
            page.goto(product["url"], wait_until="domcontentloaded", timeout=90000)
            page.wait_for_timeout(2500)

            html = page.content()
            soup = BeautifulSoup(html, "html.parser")

            title = extract_title(soup)
            image_url = extract_image(soup, product["url"])
            price_original, price_discount, discount_active = extract_prices(html)

            if title == "" or price_original is None:
                continue

            items.append({
                "scraped_title": title,
                "normalized_title": title,
                "kit_type": product["kit_type"],
                "audience": product["audience"],
                "garment_type": "camiseta",
                "version_type": "fan",
                "product_url": product["url"],
                "image_url": image_url,
                "price_original": float(price_original),
                "price_discount": float(price_discount) if price_discount is not None else None,
                "discount_active": int(discount_active),
                "source_card_html": "",
                "source_hash": source_hash(product["url"], product["kit_type"], product["audience"]),
            })

        browser.close()

    return items


def save_to_db(items):
    conn = pymysql.connect(
        host=os.environ["DB_HOST"],
        user=os.environ["DB_USER"],
        password=os.environ["DB_PASS"],
        database=os.environ["DB_NAME"],
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
    )

    try:
        with conn.cursor() as cur:
            cur.execute("SELECT id FROM clubs WHERE club_name = %s AND is_active = 1 LIMIT 1", (CLUB_NAME,))
            club = cur.fetchone()
            if not club:
                raise RuntimeError("No existe Osasuna en la tabla clubs.")

            club_id = int(club["id"])

            for item in items:
                cur.execute("""
                    INSERT INTO products
                    (
                        club_id, source_hash, scraped_title, normalized_title, kit_type,
                        audience, garment_type, version_type, product_url, image_url,
                        price_original, price_discount, discount_active, source_card_html,
                        last_seen_at, is_active
                    )
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, NOW(), 1)
                    ON DUPLICATE KEY UPDATE
                        scraped_title = VALUES(scraped_title),
                        normalized_title = VALUES(normalized_title),
                        kit_type = VALUES(kit_type),
                        audience = VALUES(audience),
                        garment_type = VALUES(garment_type),
                        version_type = VALUES(version_type),
                        product_url = VALUES(product_url),
                        image_url = VALUES(image_url),
                        price_original = VALUES(price_original),
                        price_discount = VALUES(price_discount),
                        discount_active = VALUES(discount_active),
                        source_card_html = VALUES(source_card_html),
                        last_seen_at = NOW(),
                        is_active = 1
                """, (
                    club_id,
                    item["source_hash"],
                    item["scraped_title"],
                    item["normalized_title"],
                    item["kit_type"],
                    item["audience"],
                    item["garment_type"],
                    item["version_type"],
                    item["product_url"],
                    item["image_url"],
                    item["price_original"],
                    item["price_discount"],
                    item["discount_active"],
                    item["source_card_html"],
                ))

        conn.commit()
    finally:
        conn.close()


def main():
    items = scrape_products()
    save_to_db(items)
    print(json.dumps({
        "ok": True,
        "total": len(items),
        "items": items,
    }, ensure_ascii=False))


if __name__ == "__main__":
    main()

