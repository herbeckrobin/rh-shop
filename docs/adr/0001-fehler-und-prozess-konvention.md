# 0001 Fehler-, Prozess- und Ladezustands-Konvention

Status: akzeptiert (2026-07-25)

## Kontext

rh-shop verarbeitet Zahlungen und Bestellungen, also Prozesse die abbrechen können
(Stripe nicht erreichbar, DB-Fehler, Netzwerk, fehlgeschlagene Mail). Es braucht eine
einheitliche Linie, wie mit Fehlern umgegangen wird, an der sich jedes Feature orientiert.

Wichtig: **rh-shop loggt selbst nicht** und ruft kein Monitoring-SDK auf. Die Sichtbarkeit
von Fehlern ist Aufgabe der Umgebung:

- **rh-monitor** ruft `\Sentry\init()` mit den Default-Integrationen. Die registrieren die
  globalen Error-/Exception-/Fatal-Handler, jede *uncaught* Exception und jeder PHP-Fehler
  wird automatisch erfasst. Quelle: <https://docs.sentry.io/platforms/php/integrations/>.
- **WordPress REST** fängt Exceptions im Route-Callback nicht ab (`respond_to_request()`
  ruft `call_user_func($handler['callback'], $request)` ohne try/catch). Eine geworfene
  Exception propagiert also, wird zum Fatal, WP gibt selbst einen 500 zurück und rh-monitor
  erfasst sie. Quelle: <https://developer.wordpress.org/reference/classes/wp_rest_server/respond_to_request/>.
- **Fehlgeschlagene Mails** feuern das WP-native `wp_mail_failed`. Das Aufzeichnen gehört ins
  SMTP- bzw. Monitoring-Plugin, nicht in rh-shop. Quelle:
  <https://developer.wordpress.org/reference/hooks/wp_mail_failed/>.

## Entscheidung

### 1. Server: erwartbar vs. unerwartet (die eine Grenze)

Leitfrage bei jedem Fehler: **"Kann ich dem Kunden sinnvoll etwas sagen und den Prozess
sauber abbrechen?"**

- **Ja (erwartbar)** -> `WP_Error` mit einer sauberen, **generischen** Kundenmeldung und
  passendem HTTP-Status. Kein roher Fremd-Text (z.B. Stripe-Fehlermeldung) nach aussen. Das
  ist normaler Ablauf, kein Bug, also bewusst NICHT im Monitoring.
  Beispiele: leerer Warenkorb (400), nicht konfiguriert (503), Stripe-Start scheitert (502).
- **Nein (unerwartet)** -> die Exception NICHT fangen, propagieren lassen. WP gibt 500,
  rh-monitor erfasst. Niemals still schlucken.

### 2. Prozesse sauber abbrechen / weiterverarbeiten

- **Idempotenz an der Zustandsgrenze:** Zahlung wird über den paid-Guard genau einmal
  verarbeitet (`OrderStore::markPaidByPaymentIntent`), ein wiederholter Webhook fulfilled
  nicht doppelt.
- **Webhook:** verifizierte Signatur + bekanntes Event -> Fulfillment. Fehler im
  Fulfillment propagieren (500 -> Stripe wiederholt, Monitoring sieht es), der paid-Guard
  blockt beim Retry das zweite Fulfillment. Unbekannter/fremder PaymentIntent -> sauberes
  200, kein Retry. Ungültige Signatur -> 400.
- **Best-effort-Teilschritte:** was den Kernprozess nicht blockieren darf, gibt bei einem
  erwartbaren Fehler `null`/`[]` zurück statt zu werfen. Beispiel: die Stripe-Rechnung fängt
  ihre `ApiErrorException` und liefert `null`, die Bestätigungsmail geht trotzdem raus.
- **Mail:** `wp_mail` wirft nicht (fängt intern und feuert `wp_mail_failed`). Ein
  Mail-Fehlschlag bricht die bereits bezahlte Bestellung nicht ab. rh-shop wertet den
  Rückgabewert nicht aus, das Aufzeichnen ist rh-smtp/rh-monitor.

### 3. Frontend: eine Request-Schicht, sichtbare Zustände, keine stillen Fehler

In `assets/js/shop.js`:

- **`request(path, body)`** ist die einzige Cart-Request-Funktion: prüft `response.ok`,
  liest bei Fehler die `WP_Error`-Meldung (`{code, message}`) für die Anzeige, wirft sonst
  einen freundlichen Netzwerkfehler. Kein Weiterverarbeiten eines 4xx/5xx als "State".
- **`withPending(el, promise)`** ist der einheitliche Ladezustand (Sperren + Spinner via
  `.is-pending`, danach zurücksetzen). Jede async-Aktion nutzt ihn.
- **`showError(el, message)`** setzt die Meldung in einen Fehler-Slot der Komponente
  (`role="status"`/`alert`), der beim nächsten Erfolg geleert wird.
- Jede async-Aktion hat ein `.catch`. Kein stiller Fehlschlag.

Das checkout-Script (`assets/js/checkout.js`) folgt derselben Form (ok-Prüfung, Spinner,
`.catch` mit Meldung), bleibt aber eigenständig (kein Abhängigkeits-Kopplung an shop.js).

## Konsequenzen

- Neue Endpoints/Blocks orientieren sich an dieser Grenze: erwartbar -> WP_Error,
  unerwartet -> propagieren, Frontend -> `request` + `withPending` + `showError`.
- rh-shop bleibt frei von Log-Code, `error_log` und Monitoring-Aufrufen. Wer Fehler sehen
  will, aktiviert rh-monitor; wer Mail-Zustellung prüfen will, rh-smtp.
- Grenze der aktuellen Lösung: ein Fulfillment, das NACH `markPaid` mittendrin abbricht,
  wird beim Webhook-Retry vom paid-Guard nicht erneut ausgeführt (Teil-Zustand, im
  Monitoring sichtbar). Ein separates `fulfilled`-Flag mit resume-barem, idempotentem
  Fulfillment wäre die nächste Stufe, kostet aber eine Tabellen-Migration und ist bewusst
  aufgeschoben.
