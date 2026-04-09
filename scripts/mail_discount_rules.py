import re

ALL_CLUBS = [
    "Athletic Club",
    "Atletico de Madrid",
    "CA Osasuna",
    "RC Celta",
    "Deportivo Alaves",
    "Elche CF",
    "FC Barcelona",
    "Getafe CF",
    "Girona FC",
    "Levante UD",
    "Rayo Vallecano",
    "RCD Espanyol",
    "RCD Mallorca",
    "Real Betis",
    "Real Madrid",
    "Real Oviedo",
    "Real Sociedad",
    "Sevilla FC",
    "Valencia CF",
    "Villarreal CF",
]

CLUB_MEMBERSHIP_NAMES = {
    "Athletic Club": ["socio", "socia", "club athletic", "gazte abonoa"],
    "Atletico de Madrid": ["socio", "abonado"],
    "CA Osasuna": ["abonado", "soy rojill@", "soy rojilla","rojillo","rojill@"],
    "RC Celta": ["celtista", "celtista as celtas", "celtista colaborador", "celtista abonado"],
    "Deportivo Alaves": ["abonado","socio"],
    "Elche CF": ["abonado","socio"],
    "FC Barcelona": ["culer", "culers premium", "socio"],
    "Getafe CF": ["abonado"],
    "Girona FC": ["soci", "abonado"],
    "Levante UD": ["abonado", "granotes"],
    "Rayo Vallecano": ["abonado"],
    "RCD Espanyol": ["socio", "socio abonado"],
    "RCD Mallorca": ["abonado"],
    "Real Betis": ["socio soy bético", "socio soy betico", "socio soy betico digital", "palmerín", "palmerin", "bético", "betico"],
    "Real Madrid": ["socio", "madridista premium", "madridista platinum", "madridista junior", "madridista"],
    "Real Oviedo": ["abonado", "abonado oro", "abonado plata", "abonado azul"],
    "Real Sociedad": ["socio", "rs fan", "rs laguna", "realzale"],
    "Sevilla FC": ["socio abonado", "socio rojo", "socio blanco"],
    "Valencia CF": ["#yosoyvalenciacf", "socio vcf", "socio vcf abonado"],
    "Villarreal CF": ["abonado", "soc groguet", "groget"],
}

DISCOUNT_PHRASES = [
    "descuento", "descuentos", "dto", "% dto", "% de descuento",
    "rebaja", "rebajas", "oferta", "ofertas", "promo", "promoción",
    "promocion", "cupón", "cupon", "código", "codigo", "ahorra",
    "save", "off", "envío gratis", "envio gratis", "free shipping",
]

NEWSLETTER_PHRASES = [
    "newsletter", "suscríbete", "suscribete", "suscripción", "suscripcion",
    "bienvenida", "welcome", "primera compra", "first order",
]

APP_PHRASES = ["app", "aplicación", "aplicacion"]

COUPON_PHRASES = ["cupón", "cupon", "código", "codigo"]

KIT_KEYWORDS = [
    "camiseta", "camisetas", "equipación", "equipaciones",
    "equipacion", "jersey", "shirt", "shirts",
    "camiseta oficial", "equipación oficial", "equipacion oficial"
]

KIT_TYPE_KEYWORDS = {
    "1ª": ["1ª", "1a", "primera", "home"],
    "2ª": ["2ª", "2a", "segunda", "away"],
    "3ª": ["3ª", "3a", "tercera", "third"],
}

AUDIENCE_KEYWORDS = {
    "Hombre": ["hombre", "masculino", "man", "men"],
    "Mujer": ["mujer", "femenina", "femenino", "woman", "women"],
    "Niño": ["niño", "nino", "junior", "infantil", "kid", "kids"],
}

FALSE_POSITIVE_PHRASES = [
    "nuevo color", "new color", "vota", "vote", "gana una camiseta",
    "camiseta firmada", "sorteo", "sweepstake", "participa",
    "mejor jugador", "player of the month", "colección inspirada",
    "coleccion inspirada", "nuevo lanzamiento", "new drop",
    "ya disponible", "now available", "completa tu look",
    "new arrivals", "nueva colección", "nueva coleccion",
]

PRICE_MARKDOWN_RE = re.compile(
    r"(antes|ahora|precio|price|sale|rebaja|oferta).{0,80}(€)|"
    r"(€).{0,80}(antes|ahora|precio|price|sale|rebaja|oferta)",
    re.I
)

PERCENT_RE = re.compile(r"(\d{1,3}\s?%)", re.I)
EURO_RE = re.compile(r"(\d{1,4}(?:[.,]\d{2})?\s?€)", re.I)
URL_RE = re.compile(r"https?://[^\s\"'>]+", re.I)


def clean_text(text: str) -> str:
    return re.sub(r"\s+", " ", (text or "")).strip()


def contains_discount_signal(text: str) -> bool:
    t = (text or "").lower()
    has_phrase = any(p in t for p in DISCOUNT_PHRASES)
    has_percent = bool(PERCENT_RE.search(t))
    return has_phrase or has_percent


def contains_kit_signal(text: str) -> bool:
    t = (text or "").lower()
    return any(k in t for k in KIT_KEYWORDS)


def looks_like_product_markdown(text: str) -> bool:
    return bool(PRICE_MARKDOWN_RE.search(text or ""))


def is_false_positive(text: str) -> bool:
    t = (text or "").lower()
    return any(p in t for p in FALSE_POSITIVE_PHRASES)


def detect_mechanism_family(text: str, club_name: str | None = None) -> str | None:
    t = (text or "").lower()

    if club_name:
        for label in CLUB_MEMBERSHIP_NAMES.get(club_name, []):
            if label.lower() in t:
                return "membership"

    if any(p in t for p in NEWSLETTER_PHRASES):
        return "newsletter"

    if any(p in t for p in APP_PHRASES):
        return "app"

    if any(p in t for p in COUPON_PHRASES):
        return "coupon"

    return None


def detect_membership_label(club_name: str, text: str) -> str | None:
    t = (text or "").lower()
    for label in CLUB_MEMBERSHIP_NAMES.get(club_name, []):
        if label.lower() in t:
            return label
    return None


def extract_discount_value(text: str) -> str:
    m = PERCENT_RE.search(text or "")
    if m:
        return m.group(1).replace(" ", "")
    return ""


def extract_applies_to(text: str) -> str:
    t = (text or "").lower()
    found = []
    for label, keywords in KIT_TYPE_KEYWORDS.items():
        if any(k in t for k in keywords):
            found.append(label)

    if found:
        return ", ".join(found) + " equipación"

    return "Camisetas / equipaciones"


def extract_audience(text: str) -> str:
    t = (text or "").lower()
    found = []
    for audience, keywords in AUDIENCE_KEYWORDS.items():
        if any(k in t for k in keywords):
            found.append(audience)

    if not found:
        return "Hombre / Mujer / Niño"

    ordered = []
    for label in ["Hombre", "Mujer", "Niño"]:
        if label in found:
            ordered.append(label)
    return " / ".join(ordered)


def extract_best_url(text: str) -> str:
    matches = URL_RE.findall(text or "")
    return matches[0] if matches else ""


def build_title(club_name: str, mechanism_family: str, mechanism_label: str | None) -> str:
    if mechanism_family == "membership":
        return f"Descuento para {mechanism_label or 'socios'}"
    if mechanism_family == "newsletter":
        return "Descuento por suscripción a newsletter"
    if mechanism_family == "app":
        return "Descuento por compra desde la app"
    if mechanism_family == "coupon":
        return "Descuento con cupón promocional"
    return f"Promoción de {club_name}"


def build_signature(club_name: str, mechanism_family: str, applies_to: str, audience: str) -> str:
    return f"{club_name}|{mechanism_family}|{applies_to}|{audience}"
