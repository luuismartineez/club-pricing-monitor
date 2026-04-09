#!/usr/bin/env python3
import hashlib
import json
import sys
import warnings
from datetime import datetime, date

warnings.filterwarnings("ignore")

try:
    import pymysql
    from mail_discount_rules import (
        clean_text,
        contains_discount_signal,
        detect_mechanism_family,
        detect_membership_label,
        contains_kit_signal,
        extract_discount_value,
        extract_applies_to,
        extract_audience,
        looks_like_product_markdown,
        extract_best_url,
        build_title,
        build_signature,
        is_false_positive,
    )
except Exception as exc:
    sys.stdout.write(json.dumps({
        "ok": False,
        "error": "Faltan dependencias Python o mail_discount_rules.py",
        "details": str(exc),
    }, ensure_ascii=False))
    sys.exit(0)

GENERAL_MAIL_DB = {
    "host": "localhost",
    "user": "growth",
    "password": "RSRudl2q#fv?4ed5",
    "database": "growth_",
    "charset": "utf8mb4",
    "cursorclass": pymysql.cursors.DictCursor,
}

OUR_DB = {
    "host": "localhost",
    "user": "luis3",
    "password": "colchonaco33",
    "database": "BBDDCLUBSLUIS",
    "charset": "utf8mb4",
    "cursorclass": pymysql.cursors.DictCursor,
}

CLUB_ALIASES = {
    "Real Madrid": ["real madrid", "madridista", "realmadrid"],
    "Real Betis": ["real betis", "betis", "soy bético", "soy betico", "palmerín", "palmerin"],
    "Athletic Club": ["athletic club", "club athletic"],
    "Atletico de Madrid": ["atlético de madrid", "atletico de madrid"],
    "RC Celta": ["rc celta", "celtista", "celta"],
    "Deportivo Alaves": ["deportivo alavés", "deportivo alaves", "alavés", "alaves"],
    "Elche CF": ["elche cf", "elche"],
    "Girona FC": ["girona fc", "girona"],
    "Levante UD": ["levante ud", "granotes", "levante"],
    "Rayo Vallecano": ["rayo vallecano", "rayo"],
    "RCD Espanyol": ["espanyol", "rcd espanyol"],
    "RCD Mallorca": ["mallorca", "rcd mallorca"],
    "Real Oviedo": ["real oviedo", "oviedo"],
    "Sevilla FC": ["sevilla fc", "sevilla"],
    "Villarreal CF": ["villarreal cf", "villarreal", "groguet"],
    "CA Osasuna": ["osasuna", "rojill", "soy rojill@"],
    "Real Sociedad": ["real sociedad", "rs fan", "realzale"],
    "Valencia CF": ["valencia cf", "#yosoyvalenciacf", "socio vcf"],
    "FC Barcelona": ["barcelona", "culer", "culers premium"],
    "Getafe CF": ["getafe cf", "getafe"],
}


def json_safe(value):
    if isinstance(value, (datetime, date)):
        return value.isoformat(sep=" ")
    return value


def json_default(value):
    return json_safe(value)


def detect_club(row: dict) -> str | None:
    explicit = clean_text(str(row.get("detected_club") or ""))
    if explicit:
        return explicit

    haystack = " ".join([
        str(row.get("from_name") or ""),
        str(row.get("from_email") or ""),
        str(row.get("subject") or ""),
        str(row.get("snippet") or ""),
        str(row.get("body_text") or ""),
    ]).lower()

    for club_name, aliases in CLUB_ALIASES.items():
        if any(alias in haystack for alias in aliases):
            return club_name

    return None


def build_source_text(row: dict) -> str:
    parts = [
        str(row.get("subject") or ""),
        str(row.get("snippet") or ""),
        str(row.get("body_text") or ""),
        str(row.get("html_clean") or ""),
    ]
    return clean_text(" ".join(parts))


def build_description(text: str, mechanism_family: str, mechanism_label: str | None) -> str:
    text = clean_text(text)

    stop_words = [
        "política de privacidad", "politica de privacidad",
        "gestiona tus preferencias", "unsubscribe", "darse de baja",
        "ver en navegador", "view in browser", "síguenos", "siguenos",
        "facebook", "instagram", "twitter", "youtube",
    ]

    lowered = text.lower()
    for stop in stop_words:
        pos = lowered.find(stop)
        if pos > 0:
            text = text[:pos].strip()
            lowered = text.lower()

    if len(text) > 180:
        text = text[:177].rstrip() + "..."

    if mechanism_family == "membership" and mechanism_label:
        return f"Ventaja para {mechanism_label}: {text}" if text else f"Ventaja para {mechanism_label} en camisetas o equipaciones."

    if mechanism_family == "newsletter":
        return f"Suscríbete y obtén descuento en camisetas o equipaciones. {text}".strip()

    if mechanism_family == "app":
        return f"Compra desde la app con descuento en camisetas o equipaciones. {text}".strip()

    if mechanism_family == "coupon":
        return f"Cupón promocional para camisetas o equipaciones. {text}".strip()

    return text


def fetch_recent_emails() -> list[dict]:
    conn = pymysql.connect(**GENERAL_MAIL_DB)
    try:
        with conn.cursor() as cur:
            cur.execute("""
                SELECT
                    id,
                    email_date,
                    received_at,
                    from_name,
                    from_email,
                    subject,
                    snippet,
                    body_text,
                    detected_club,
                    is_duplicate,
                    created_at,
                    html_clean
                FROM emails
                WHERE is_duplicate = 0
                ORDER BY COALESCE(received_at, email_date, created_at) DESC
                LIMIT 500
            """)
            return cur.fetchall()
    finally:
        conn.close()


def extract_candidate(row: dict) -> dict | None:
    club_name = detect_club(row)
    if not club_name:
        return None

    text = build_source_text(row)
    if not text:
        return None

    if is_false_positive(text):
        return None

    if not contains_discount_signal(text):
        return None

    if not contains_kit_signal(text):
        return None

    if looks_like_product_markdown(text):
        return None

    mechanism_family = detect_mechanism_family(text, club_name)
    if not mechanism_family:
        return None

    discount_value = extract_discount_value(text)
    if discount_value == "":
        return None

    mechanism_label = ""
    if mechanism_family == "membership":
        mechanism_label = detect_membership_label(club_name, text) or "socios"

    applies_to = extract_applies_to(text)
    audience = extract_audience(text)
    source_url = extract_best_url(text)
    title = build_title(club_name, mechanism_family, mechanism_label)
    description = build_description(text, mechanism_family, mechanism_label)

    source_mail_id = str(row.get("id"))
    source_sender = clean_text(str(row.get("from_email") or ""))
    source_subject = clean_text(str(row.get("subject") or ""))
    source_received_at = json_safe(row.get("received_at") or row.get("email_date") or row.get("created_at"))
    raw_excerpt = text[:2000]

    signature_base = build_signature(club_name, mechanism_family, applies_to, audience)
    signature_hash = hashlib.md5(signature_base.encode("utf-8")).hexdigest()

    return {
        "club_name": club_name,
        "source_mail_id": source_mail_id,
        "source_sender": source_sender,
        "source_subject": source_subject,
        "source_received_at": source_received_at,
        "mechanism_family": mechanism_family,
        "mechanism_label": mechanism_label,
        "discount_value": discount_value,
        "applies_to": applies_to,
        "audience": audience,
        "title": title,
        "description": description,
        "source_url": source_url,
        "raw_excerpt": raw_excerpt,
        "signature_hash": signature_hash,
    }


def upsert_candidate(cur, item: dict):
    cur.execute("""
        INSERT INTO mail_discount_candidates
        (
            club_name, source_mail_id, source_sender, source_subject, source_received_at,
            mechanism_family, mechanism_label, discount_value, applies_to, audience,
            title, description, source_url, raw_excerpt, signature_hash
        )
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
        ON DUPLICATE KEY UPDATE
            source_mail_id = VALUES(source_mail_id),
            source_sender = VALUES(source_sender),
            source_subject = VALUES(source_subject),
            source_received_at = VALUES(source_received_at),
            mechanism_family = VALUES(mechanism_family),
            mechanism_label = VALUES(mechanism_label),
            discount_value = VALUES(discount_value),
            applies_to = VALUES(applies_to),
            audience = VALUES(audience),
            title = VALUES(title),
            description = VALUES(description),
            source_url = VALUES(source_url),
            raw_excerpt = VALUES(raw_excerpt)
    """, (
        item["club_name"],
        item["source_mail_id"],
        item["source_sender"],
        item["source_subject"],
        item["source_received_at"],
        item["mechanism_family"],
        item["mechanism_label"],
        item["discount_value"],
        item["applies_to"],
        item["audience"],
        item["title"],
        item["description"],
        item["source_url"],
        item["raw_excerpt"],
        item["signature_hash"],
    ))


def insert_history(cur, live_offer_id: int, event_type: str, payload: dict):
    cur.execute("""
        INSERT INTO mail_discount_history
        (live_offer_id, event_type, payload_json)
        VALUES (%s, %s, %s)
    """, (
        live_offer_id,
        event_type,
        json.dumps(payload, ensure_ascii=False, default=json_default),
    ))


def sync_live(cur, item: dict):
    cur.execute("""
        SELECT id
        FROM mail_discount_live
        WHERE signature_hash = %s
        LIMIT 1
    """, (item["signature_hash"],))
    existing = cur.fetchone()

    if not existing:
        cur.execute("""
            INSERT INTO mail_discount_live
            (
                club_name, mechanism_family, mechanism_label, discount_value,
                applies_to, audience, title, description, source_url,
                source_mail_id, source_received_at, signature_hash,
                is_active, missing_runs, first_seen_at, last_seen_at, updated_at
            )
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, 1, 0, NOW(), NOW(), NOW())
        """, (
            item["club_name"],
            item["mechanism_family"],
            item["mechanism_label"],
            item["discount_value"],
            item["applies_to"],
            item["audience"],
            item["title"],
            item["description"],
            item["source_url"],
            item["source_mail_id"],
            item["source_received_at"],
            item["signature_hash"],
        ))
        live_offer_id = cur.lastrowid
        insert_history(cur, live_offer_id, "insert", item)
        return "insert"

    cur.execute("""
        UPDATE mail_discount_live
        SET
            mechanism_label = %s,
            discount_value = %s,
            title = %s,
            description = %s,
            source_url = %s,
            source_mail_id = %s,
            source_received_at = %s,
            is_active = 1,
            missing_runs = 0,
            last_seen_at = NOW(),
            updated_at = NOW()
        WHERE id = %s
    """, (
        item["mechanism_label"],
        item["discount_value"],
        item["title"],
        item["description"],
        item["source_url"],
        item["source_mail_id"],
        item["source_received_at"],
        existing["id"],
    ))
    insert_history(cur, existing["id"], "update", item)
    return "update"


def mark_missing_offers(cur, seen_hashes: set[str]):
    cur.execute("""
        SELECT id, signature_hash, missing_runs
        FROM mail_discount_live
        WHERE is_active = 1
    """)
    rows = cur.fetchall()
    deactivated = 0

    for row in rows:
        if row["signature_hash"] in seen_hashes:
            continue

        new_missing = int(row["missing_runs"]) + 1
        if new_missing >= 3:
            cur.execute("""
                UPDATE mail_discount_live
                SET is_active = 0, missing_runs = %s, updated_at = NOW()
                WHERE id = %s
            """, (new_missing, row["id"]))
            insert_history(cur, row["id"], "deactivate", {"missing_runs": new_missing})
            deactivated += 1
        else:
            cur.execute("""
                UPDATE mail_discount_live
                SET missing_runs = %s, updated_at = NOW()
                WHERE id = %s
            """, (new_missing, row["id"]))

    return deactivated


def run_sync():
    emails = fetch_recent_emails()
    extracted = []
    seen_hashes = set()

    for row in emails:
        item = extract_candidate(row)
        if not item:
            continue
        extracted.append(item)
        seen_hashes.add(item["signature_hash"])

    conn = pymysql.connect(**OUR_DB)
    try:
        with conn.cursor() as cur:
            inserts = 0
            updates = 0

            for item in extracted:
                upsert_candidate(cur, item)
                action = sync_live(cur, item)
                if action == "insert":
                    inserts += 1
                else:
                    updates += 1

            deactivated = mark_missing_offers(cur, seen_hashes)

        conn.commit()
    finally:
        conn.close()

    return {
        "ok": True,
        "emails_checked": len(emails),
        "candidates_found": len(extracted),
        "live_inserts": inserts,
        "live_updates": updates,
        "live_deactivated": deactivated,
    }


def main():
    try:
        result = run_sync()
        sys.stdout.write(json.dumps(result, ensure_ascii=False, default=json_default))
    except Exception as exc:
        sys.stdout.write(json.dumps({
            "ok": False,
            "error": "Error ejecutando mail_discount_sync.py",
            "details": str(exc),
        }, ensure_ascii=False, default=json_default))


if __name__ == "__main__":
    main()
