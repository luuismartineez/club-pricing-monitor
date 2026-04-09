#!/usr/bin/env python3
import hashlib
import json
import re
import sys
import warnings
from urllib.parse import urljoin, urlparse

warnings.filterwarnings("ignore")

try:
    import pymysql
    import requests
    from bs4 import BeautifulSoup
    from club_discount_sources import CLUB_SOURCES
except Exception as exc:
    sys.stdout.write(json.dumps({
        "ok": False,
        "error": "Faltan dependencias Python o el fichero club_discount_sources.py",
        "details": str(exc),
    }, ensure_ascii=False))
    sys.exit(0)

USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/124.0.0.0 Safari/537.36"
)

PROMO_LINK_HINTS = [
    "newsletter", "suscrib", "welcome", "bienvenida", "club", "member",
    "membership", "socio", "abonado", "app", "promo", "descuento",
    "oferta", "rebajas", "beneficios", "ventajas", "fidelidad", "faq",
    "conditions", "condiciones", "legal", "help"
]

MECHANISM_RULES = [
    ("miembros", "Al hacerse socio", ["platinum", "member", "membership", "socio", "socios", "abonado", "abonados", "club"]),
    ("newsletter", "Por suscribirse a la newsletter", ["newsletter", "suscríbete", "suscribete", "welcome", "bienvenida", "primera compra"]),
    ("app", "Por comprar desde la app", ["app", "aplicación", "aplicacion"]),
    ("coupon", "Con cupón promocional", ["cupón", "cupon", "código", "codigo"]),
]

PERCENT_RE = re.compile(r"(\d{1,3}\s?%)", re.I)
TARGET_RE = re.compile(
    r"(camiseta|equipación|equipacion|jersey|shirt).{0,120}(1ª|1a|primera|2ª|2a|segunda|3ª|3a|tercera|home|away|third)|"
    r"(1ª|1a|primera|2ª|2a|segunda|3ª|3a|tercera|home|away|third).{0,120}(camiseta|equipación|equipacion|jersey|shirt)",
    re.I
)
NEGATIVE_RE = re.compile(
    r"(rebaja|descuento|sale|oferta).{0,80}(precio|price|antes|ahora)|"
    r"(precio|price).{0,80}(rebaja|descuento|sale|oferta)",
    re.I
)

DB_CONFIG = {
    "host": "localhost",
    "user": "luis3",
    "password": "colchonaco33",
    "database": "BBDDCLUBSLUIS",
    "charset": "utf8mb4",
    "cursorclass": pymysql.cursors.DictCursor,
}


def clean_text(text: str) -> str:
    return re.sub(r"\s+", " ", text).strip()


def same_domain(a: str, b: str) -> bool:
    return urlparse(a).netloc == urlparse(b).netloc


def get_page(url: str):
    session = requests.Session()
    session.headers.update({
        "User-Agent": USER_AGENT,
        "Accept-Language": "es-ES,es;q=0.9,en;q=0.8",
    })
    response = session.get(url, timeout=25, allow_redirects=True)
    response.raise_for_status()
    return response.text, response.url


def extract_page_title(soup: BeautifulSoup) -> str:
    if soup.title and soup.title.string:
        return clean_text(soup.title.string)
    h1 = soup.find("h1")
    if h1:
        return clean_text(h1.get_text(" ", strip=True))
    return ""


def discover_links(base_url: str, html: str):
    soup = BeautifulSoup(html, "html.parser")
    out = []
    seen = set()

    for tag in soup.select("a[href]"):
        href = (tag.get("href") or "").strip()
        if not href:
            continue

        absolute = urljoin(base_url, href)
        if not same_domain(base_url, absolute):
            continue

        anchor_text = clean_text(tag.get_text(" ", strip=True))
        haystack = (absolute + " " + anchor_text).lower()

        if any(hint in haystack for hint in PROMO_LINK_HINTS):
            normalized = absolute.split("#", 1)[0]
            if normalized in seen:
                continue
            seen.add(normalized)
            out.append(normalized)

    return out[:25]


def classify_mechanism(text: str):
    t = text.lower()
    for mechanism_type, mechanism_label, keywords in MECHANISM_RULES:
        if any(keyword in t for keyword in keywords):
            return mechanism_type, mechanism_label
    return None, None


def extract_discount_value(text: str) -> str:
    match = PERCENT_RE.search(text)
    if match:
        return match.group(1).replace(" ", "")
    return ""


def extract_audience(text: str) -> str:
    t = text.lower()
    found = []

    if any(x in t for x in ["hombre", "man", "men", "masculino", "adulto"]):
        found.append("Hombre")
    if any(x in t for x in ["mujer", "women", "woman", "femenina", "femenino"]):
        found.append("Mujer")
    if any(x in t for x in ["junior", "infantil", "niño", "nino", "kids", "kid"]):
        found.append("Niño")

    if not found:
        return "Hombre / Mujer / Niño"

    ordered = []
    for label in ["Hombre", "Mujer", "Niño"]:
        if label in found:
            ordered.append(label)

    return " / ".join(ordered)


def extract_applies_to(text: str) -> str:
    t = text.lower()
    kits = []

    if any(x in t for x in ["1ª", "1a", "primera", "home"]):
        kits.append("1ª")
    if any(x in t for x in ["2ª", "2a", "segunda", "away"]):
        kits.append("2ª")
    if any(x in t for x in ["3ª", "3a", "tercera", "third"]):
        kits.append("3ª")

    if kits:
        return ", ".join(kits) + " equipación"

    return "Camisetas / equipaciones"


def concise_conditions(text: str) -> str:
    text = clean_text(text)

    for stop in [
        "política de privacidad",
        "politica de privacidad",
        "envíos y devoluciones",
        "envios y devoluciones",
        "localizador de tiendas",
        "mi cuenta",
        "rastrear mi pedido",
        "english",
        "français",
        "frances",
    ]:
        pos = text.lower().find(stop)
        if pos > 0:
            text = text[:pos].strip()

    if len(text) > 220:
        text = text[:217].rstrip() + "..."

    return text


def score_candidate(text: str, mechanism_type: str, discount_value: str) -> int:
    score = 0

    if mechanism_type:
        score += 30
    if discount_value:
        score += 25
    if TARGET_RE.search(text):
        score += 30
    if (
        "camiseta oficial" in text.lower()
        or "equipación oficial" in text.lower()
        or "equipacion oficial" in text.lower()
    ):
        score += 20
    if NEGATIVE_RE.search(text):
        score -= 40

    return score


def extract_candidates_from_page(source_type: str, url: str, html: str):
    soup = BeautifulSoup(html, "html.parser")
    page_title = extract_page_title(soup)

    texts = []
    for tag in soup.find_all(["section", "article", "div", "li", "p"]):
        block = clean_text(tag.get_text(" ", strip=True))
        if len(block) < 40:
            continue
        texts.append(block)

    candidates = []

    for block in texts:
        mechanism_type, mechanism_label = classify_mechanism(block)
        if not mechanism_type:
            continue

        if (
            not TARGET_RE.search(block)
            and "camiseta oficial" not in block.lower()
            and "equipación oficial" not in block.lower()
            and "equipacion oficial" not in block.lower()
        ):
            continue

        discount_value = extract_discount_value(block)
        applies_to = extract_applies_to(block)
        audience = extract_audience(block)
        conditions_text = concise_conditions(block)
        score = score_candidate(block, mechanism_type, discount_value)

        if score < 40:
            continue

        raw_hash = hashlib.md5(
            (url + "|" + mechanism_label + "|" + discount_value + "|" + applies_to + "|" + audience).encode("utf-8")
        ).hexdigest()

        candidates.append({
            "source_type": source_type,
            "source_url": url,
            "page_title": page_title,
            "mechanism_type": mechanism_type,
            "mechanism_label": mechanism_label,
            "discount_value": discount_value or "No especificado",
            "applies_to": applies_to,
            "audience": audience,
            "conditions_text": conditions_text,
            "raw_text": block[:2000],
            "score": score,
            "source_hash": raw_hash,
        })

    return candidates


def save_candidates(club_name: str, candidates: list):
    conn = pymysql.connect(**DB_CONFIG)
    try:
        with conn.cursor() as cur:
            for item in candidates:
                sql = """
                    INSERT INTO discount_candidates
                    (
                        club_name,
                        source_type,
                        source_url,
                        page_title,
                        mechanism_type,
                        mechanism_label,
                        discount_value,
                        applies_to,
                        audience,
                        conditions_text,
                        raw_text,
                        score,
                        source_hash
                    )
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                    ON DUPLICATE KEY UPDATE
                        page_title = VALUES(page_title),
                        mechanism_type = VALUES(mechanism_type),
                        mechanism_label = VALUES(mechanism_label),
                        discount_value = VALUES(discount_value),
                        applies_to = VALUES(applies_to),
                        audience = VALUES(audience),
                        conditions_text = VALUES(conditions_text),
                        raw_text = VALUES(raw_text),
                        score = VALUES(score)
                """

                cur.execute(sql, (
                    club_name,
                    item["source_type"],
                    item["source_url"],
                    item["page_title"],
                    item["mechanism_type"],
                    item["mechanism_label"],
                    item["discount_value"],
                    item["applies_to"],
                    item["audience"],
                    item["conditions_text"],
                    item["raw_text"],
                    item["score"],
                    item["source_hash"],
                ))
        conn.commit()
    finally:
        conn.close()


def run_for_club(club_name: str):
    if club_name not in CLUB_SOURCES:
        return {"ok": False, "error": f"Club no reconocido: {club_name}"}

    sources = CLUB_SOURCES[club_name]
    visited_urls = []
    all_candidates = []

    for source_type, source_url in [("official", sources["official"]), ("store", sources["store"])]:
        try:
            html, final_url = get_page(source_url)
            visited_urls.append({"url": final_url, "status": "ok"})
            all_candidates.extend(extract_candidates_from_page(source_type, final_url, html))

            for extra_url in discover_links(final_url, html):
                try:
                    extra_html, extra_final = get_page(extra_url)
                    visited_urls.append({"url": extra_final, "status": "ok"})
                    all_candidates.extend(extract_candidates_from_page(source_type, extra_final, extra_html))
                except Exception as exc:
                    visited_urls.append({"url": extra_url, "status": "error", "message": str(exc)})

        except Exception as exc:
            visited_urls.append({"url": source_url, "status": "error", "message": str(exc)})

    unique = {}
    for item in all_candidates:
        key = item["source_hash"]
        if key not in unique or item["score"] > unique[key]["score"]:
            unique[key] = item

    final_candidates = list(unique.values())
    save_candidates(club_name, final_candidates)

    return {
        "ok": True,
        "club_name": club_name,
        "visited_count": len(visited_urls),
        "candidates_found": len(final_candidates),
        "visited_urls": visited_urls,
    }


def main():
    try:
        if len(sys.argv) < 2:
            sys.stdout.write(json.dumps({
                "ok": False,
                "error": "Uso: python discount_discovery.py <club>"
            }, ensure_ascii=False))
            return

        club_name = " ".join(sys.argv[1:]).strip()
        result = run_for_club(club_name)
        sys.stdout.write(json.dumps(result, ensure_ascii=False))

    except Exception as exc:
        sys.stdout.write(json.dumps({
            "ok": False,
            "error": "Error ejecutando el descubridor.",
            "details": str(exc),
        }, ensure_ascii=False))


if __name__ == "__main__":
    main()
