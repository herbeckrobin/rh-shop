# RH Shop

Schlanker Shop für kleine Sortimente. Katalog in WordPress, Zahlung über Stripe. Teil der [rh-blueprint](https://github.com/herbeckrobin) Kollektion.

## Idee

WooCommerce ist für einen Merch-Shop mit einer Handvoll Produkten zu viel. RH Shop macht nur das Nötige: Produkte im WordPress-Editor pflegen, bezahlen über Stripe. Das Plugin ist die Katalog- und Bestell-Verwaltung, die Zahlung erledigt Stripe.

## Architektur (Kurzfassung)

- **Katalog in WordPress**, nicht in Stripe: Produkt-CPT `rh_product` + Kategorie-Taxonomie. Varianten (Größe, Farbe, SKU, Preis, Bestand) als Post-Meta, editierbar über eine Meta-Box. Grund: Stripe hat kein Varianten-, Inventar- oder Kategorie-Konzept.
- **Zahlung über Stripe**: der Warenkorb-Betrag wird serverseitig aus dem Katalog gerechnet und über Stripe eingezogen. Stripe kennt den Katalog nicht.
- **Checkout DE-rechtssicher**: eigene Bestellübersicht mit Pflichtangaben, Pflicht-Checkboxen und "Zahlungspflichtig bestellen"-Button (§312j BGB) auf der eigenen Seite, die Stripe-Zahl-UI danach.

## Stand

Fundament: Produktmodell (CPT + Varianten). Stripe-Anbindung, Warenkorb, Checkout, Bestellungen und der Rechts-Layer folgen als eigene Bausteine.

## Setup (lokal, DDEV)

```bash
composer install
# Symlink ins WordPress-Plugin-Verzeichnis + aktivieren
```

## Dependencies

- `rh/blueprint-core`, `stripe/stripe-php`, `yahnis-elsts/plugin-update-checker`
