<?php

declare(strict_types=1);

use RhShop\Checkout\Totals;
use RhShop\Orders\Order;

// Enthaltene USt aus dem Brutto herausrechnen (nicht aufschlagen).
eq(Totals::includedTax(11900, Order::TAX_VAT, 19), 1900, '119,00 brutto -> 19,00 USt');
eq(Totals::includedTax(61700, Order::TAX_VAT, 19), 9851, '617,00 brutto -> 98,51 USt');
eq(Totals::includedTax(2490, Order::TAX_VAT, 19), 398, '24,90 brutto -> 3,98 USt');

// Kleinunternehmer: keine USt.
eq(Totals::includedTax(61700, Order::TAX_KLEINUNTERNEHMER, 19), 0, 'Kleinunternehmer keine USt');

// Randfälle: null Betrag, Satz 0.
eq(Totals::includedTax(0, Order::TAX_VAT, 19), 0, 'Betrag 0 keine USt');
eq(Totals::includedTax(-100, Order::TAX_VAT, 19), 0, 'negativer Betrag keine USt');
eq(Totals::includedTax(11900, Order::TAX_VAT, 0), 0, 'Satz 0 keine USt');

// Anderer Satz (z.B. Schweiz 8 %) rechnet korrekt.
eq(Totals::includedTax(10800, Order::TAX_VAT, 8), 800, '108,00 brutto bei 8% -> 8,00 USt');

// Versand: leerer Warenkorb kostet nichts, egal welche Pauschale.
eq(Totals::shippingFor(0, 499, 0), 0, 'leerer Warenkorb -> kein Versand');
eq(Totals::shippingFor(0, 499, 5000), 0, 'leerer Warenkorb -> kein Versand, auch mit Schwelle');

// Ohne Gratis-Schwelle (0) gilt die Pauschale.
eq(Totals::shippingFor(2490, 499, 0), 499, 'Schwelle aus -> Pauschale');

// Mit Schwelle: unter der Schwelle Pauschale, ab der Schwelle gratis.
eq(Totals::shippingFor(4999, 499, 5000), 499, 'unter der Schwelle -> Pauschale');
eq(Totals::shippingFor(5000, 499, 5000), 0, 'genau auf der Schwelle -> gratis');
eq(Totals::shippingFor(9900, 499, 5000), 0, 'über der Schwelle -> gratis');

// Pauschale 0 bleibt 0 (kostenloser Versand generell).
eq(Totals::shippingFor(2490, 0, 5000), 0, 'Pauschale 0 -> immer gratis');

// Kompletter Zusammenbau (fromValues): unter der Gratis-Schwelle, Regelbesteuerung.
$t = Totals::fromValues(4999, 499, 5000, Order::TAX_VAT, 19);
eq($t->shippingCents, 499, 'Montage: unter Schwelle -> Versand 4,99');
eq($t->totalCents, 5498, 'Montage: Gesamt = Ware + Versand');
eq($t->taxCents, 878, 'Montage: enthaltene USt aus dem Gesamt');

// Über der Schwelle: Versand gratis, USt aus dem reinen Warenwert.
$t = Totals::fromValues(6000, 499, 5000, Order::TAX_VAT, 19);
eq($t->shippingCents, 0, 'Montage: ab Schwelle -> Versand gratis');
eq($t->totalCents, 6000, 'Montage: Gesamt ohne Versand');
eq($t->taxCents, 958, 'Montage: USt aus 60,00');

// Kleinunternehmer: Versandpauschale greift, keine USt.
$t = Totals::fromValues(5000, 499, 0, Order::TAX_KLEINUNTERNEHMER, 19);
eq($t->shippingCents, 499, 'Montage: Kleinunternehmer mit Versand');
eq($t->totalCents, 5499, 'Montage: Gesamt Kleinunternehmer');
eq($t->taxCents, 0, 'Montage: Kleinunternehmer keine USt');
