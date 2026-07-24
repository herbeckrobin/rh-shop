=== RH Shop ===
Contributors: robinherbeck
Tags: shop, ecommerce, stripe, products
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Schlanker Shop für kleine Sortimente: Katalog in WordPress, Zahlung über Stripe. Weniger Ballast als WooCommerce, Pflege in Minuten.

== Description ==

RH Shop ist der leichte Weg, ein kleines Sortiment (Merch, ein paar Produkte) zu verkaufen. Der Katalog lebt als Produkt-CPT in WordPress, gepflegt im gewohnten Editor. Die Zahlung läuft über Stripe. Kein aufgeblähtes Shop-System, nur was ein kleiner Shop wirklich braucht.

Teil der rh-blueprint Kollektion.

== Changelog ==

= 0.1.0 =
* Produkt-CPT mit Kategorien und Varianten (Größe, Farbe, SKU, Preis, Bestand) als Post-Meta, gepflegt im gewohnten Editor.
* Blocks: Produktraster, Einzelprodukt, Warenkorb, Kasse. Warenkorb als Cookie (kein Login), Preise werden immer serverseitig gerechnet.
* Kasse nach § 312j: Preisaufschlüsselung und Pflicht-Checkboxen vor dem "Zahlungspflichtig bestellen"-Button, danach eingebettete Stripe-Zahlung ohne Weiterleitung.
* Stripe-Webhook (per Klick automatisch einrichtbar) bestätigt die Zahlung serverseitig, bucht den Bestand und verschickt die Bestätigungsmails.
* Rechnung nach der Zahlung über Stripe Invoicing (optional).
* Bestell-Übersicht im Admin.
* PAngV: MwSt-Hinweis (inkl. MwSt. bzw. § 19 bei Kleinunternehmer), verlinkte Versandkosten, Grundpreis je Variante.
* Rechts-Layer: § 356a Widerrufsbutton, AGB-Zustimmung optional, amtliches Muster-Widerrufsformular, Pflichtinfos in der Bestätigungsmail, Go-Live-Check im Admin.
* Steuer wählbar: Regelbesteuerung (19 %) oder Kleinunternehmer (§ 19 UStG).
* Legt bei der Aktivierung die Seiten "Versand" und "Vielen Dank" an, die Danke-Seite zeigt den echten Zahlungsstatus.
