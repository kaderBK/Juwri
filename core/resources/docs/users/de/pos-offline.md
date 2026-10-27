---
title: Verkaufen, wenn das Internet ausfällt (Offline-POS)
audience: owner
group: Taking orders
updated: 2026-10-12
---

# Verkaufen, wenn das Internet ausfällt (Offline-POS)

Ihre Kasse verkauft weiter, wenn das Internet oder der Server nicht erreichbar ist, für begrenzte Zeit, und sendet
alles an Ihr Dashboard, sobald die Verbindung wieder da ist. Diese Seite erklärt, was weiterhin funktioniert, was
nicht, und die Grenzen.

## Was offline funktioniert

- **Verkäufe kassieren** aus dem Menü, das Ihre Kasse zuletzt heruntergeladen hat: Artikel, Optionen, Varianten, Steuern,
  Servicegebühr und die Rabatte, die Ihr Personal geben darf.
- **Barzahlungen und manuelle Zahlungen** (zum Beispiel eine Überweisung, die Sie selbst bestätigen), mit dem
  Wechselgeld, das auf der Kasse berechnet wird.
- **Belege**, die von der Kasse gedruckt werden, einschließlich des QR-Codes, wo Ihr Land einen verlangt.
- **Stornieren** eines Verkaufs, der auf dieser Kasse gemacht wurde, in dieser Schicht, bevor er synchronisiert wurde
  (mit einer Manager-PIN, falls Ihr Geschäft eine verlangt).
- **Die Schublade öffnen und schließen**, Einzahlungen und Auszahlungen sowie die Schichtübersicht dieser Kasse.
- **Kartenzahlungen**, die auf der Kasse für später gespeichert werden: nur auf einer Kasse mit der Kartenleser-App, nur
  innerhalb der Kartenlimits unten und nur sobald Ihr Zahlungsanbieter diese Funktion für Ihr Geschäft bestätigt hat. Bis
  dahin sind Offline-Kartenzahlungen ausgeschaltet und die Kasse verlangt eine andere Zahlungsart.

Wenn die Verbindung zurückkommt, sendet die Kasse jeden Verkauf, jede Zahlung und jede Stornierung der Reihe nach an Ihr
Dashboard, jeweils einmal. Ein Verkauf wird nie zweimal angelegt, selbst wenn die Kasse neu startet oder die Verbindung
mittendrin abbricht.

## Zeitlimits

| Limit | Standard | Maximum |
|---|---|---|
| Wie lange eine Kasse verkaufen darf, ohne den Server zu kontaktieren | 12 Stunden | 24 Stunden |
| Wie lange ein Verkauf, der nicht gesendet werden kann, auf der Kasse aufbewahrt wird | 7 Tage | 7 Tage |

- Nachdem die Offline-Zeit aufgebraucht ist, verweigert die Kasse **neue** Verkäufe und zeigt "Verbinden, um
  fortzufahren" an. Sie sendet weiterhin, was sie hat, und druckt weiterhin Belege. Die Stunden zählen ab dem letzten Mal,
  als die Kasse den Server erreicht hat.
- Wenn ein Verkauf nach 7 Tagen noch wartet, stoppt die Kasse neue Verkäufe und zeigt einen Manager-Hinweis an. Nichts
  wird gelöscht, bevor es gesendet wurde.
- Geschäftsinhaber können in den POS-Einstellungen eine kürzere Offline-Zeit wählen; das Plattform-Maximum ist 24 Stunden.

## Kartenlimits

Offline-Kartenzahlungen werden von der Kartenleser-App auf der Kasse gehalten, bis der Anbieter sie verarbeiten kann. Die
Kasse prüft diese Limits **bevor** die Karte gelesen wird:

| Limit | Wert |
|---|---|
| Eine Kartenzahlung | bis zu SAR 250 |
| Alle noch nicht gesendeten Kartenzahlungen, pro Kasse | bis zu SAR 2,500 |

Sie können für Ihr Geschäft niedrigere Limits festlegen, nicht höhere. Über einem Limit meldet die Kasse "benötigt eine
Verbindung" und Sie nehmen eine andere Zahlungsart.

**Das Geschäft trägt das Risiko.** Eine Offline-Kartenzahlung kann im Moment des Verkaufs nicht geprüft werden. Wird die
Karte später abgelehnt, bleibt der Verkauf in Ihren Aufzeichnungen und wird als **Karte nach Offline-Verkauf abgelehnt**
bei dieser Bestellung angezeigt, im Schichtbericht und in der Liste **Offline-Ausnahmen**, mit Betrag, Uhrzeit, Mitarbeiter
und Kasse. Juwri belastet den Kunden nicht erneut für Sie. Wenn Sie das Geld auf eine andere Weise einziehen (bar,
Überweisung), markiert ein Manager es als "anderweitig eingezogen", was eine korrigierende Zahlung hinzufügt und die
abgelehnte nie löscht. Die Kasse und Juwri speichern niemals Kartennummern.

## Browser-Kassen sind nur für Bargeld

Eine Kasse, die in einem Webbrowser läuft (nicht die Kartenleser-App), kann offline nur mit **Barzahlungen und manuellen
Zahlungen** verkaufen. Sie hat keine Kartenleser-App, daher sind Offline-Kartenzahlungen dort nicht verfügbar.

Ein Browser kann außerdem seine eigenen gespeicherten Daten löschen, wenn dem Gerät der Speicher ausgeht oder der Browser
zurückgesetzt wird. Um Ihre Verkäufe zu schützen, bittet die Kasse den Browser, seine Daten zu behalten, und zeigt eine
Warnung dazu an. Wenn eine Browser-Kasse lange offline sein könnte, senden Sie ihre Verkäufe, sobald die Verbindung wieder
da ist, und bevorzugen Sie die Tablet-App für vielbeschäftigte Filialen.

## Was Ihr Personal sehen wird

- Ein Banner "Offline" mit der Anzahl wartender Verkäufe und der verbleibenden Offline-Zeit.
- Preise, Steuern, Rundung und Belege funktionieren genauso wie online. Wurde ein Preis im Dashboard geändert, während
  die Kasse offline war, behält der Verkauf den Preis, den der Kunde gesehen und bezahlt hat.
- Nach dem Synchronisieren zeigt eine kurze Liste **Offline-Ausnahmen** für die Schicht alles an, was ein Manager
  ansehen sollte.

## Dinge, die die Verbindung brauchen (Ausnahmen)

Diese funktionieren offline nicht. Die Kasse sagt es Ihnen, wenn Sie es versuchen:

- Online-Kartenzahlungen und Zahlungslinks, Wallet- oder Guthabenzahlungen, Geschenkkarten (Verkaufen oder Einlösen),
  Einlösen von Treuepunkten (Punkte werden weiterhin gesammelt, wenn der Verkauf synchronisiert wird), Gutscheine, die
  den Server brauchen (Einmal- oder kundenspezifische Codes).
- Erstattungen von Verkäufen, die nicht auf dieser Kasse in dieser Schicht gemacht wurden, jede Kartenerstattung und das
  Stornieren einer Kartenzahlung, nachdem sie gesendet wurde.
- Kunden nachschlagen (ein auf einem Verkauf eingegebener Name und Telefonnummer ist in Ordnung) und Kundenkonten anlegen.
- Eingehende Online- und Liefer-App-Bestellungen, Fahrerzustellung, das Küchendisplay auf anderen Geräten, das Verschieben
  oder Zusammenführen eines Tisches mit einer anderen Kasse, Reservierungen und die Warteliste.
- Preise, Artikel, Steuern, Personal oder Einstellungen ändern, eine Kasse öffnen, die noch nie verbunden war, und einen
  Mitarbeiter anmelden, der nicht auf der zuletzt heruntergeladenen Liste stand.
- Berichte über die Schichtübersicht dieser Kasse hinaus (der Tagesabschlussbericht ist erst endgültig, nachdem die
  Verkäufe synchronisiert wurden).
- Steuerrechnungen, die eine behördliche Freigabe brauchen (zum Beispiel Geschäft-zu-Geschäft-Rechnungen in Saudi-Arabien).
  Vereinfachte Kundenbelege werden auf der Kasse selbst signiert.
- Verkaufen, nachdem die Offline-Zeit oder das 7-Tage-Limit abgelaufen ist.

## Wenn zwei Dinge abweichen

- **Lagerbestand:** Die Kasse kann offline einen Artikel unter Nullbestand verkaufen. Der Verkauf wird beim
  Synchronisieren akzeptiert und der Artikel landet in einem **Überverkauf**-Bericht für Sie. Artikel, die als "niemals
  überverkaufen" markiert sind, werden bei Null auf der Kasse blockiert.
- **Derselbe Tisch auf zwei Kassen:** Beide Verkäufe werden als getrennte Bestellungen behalten und markiert.
- **Eine Schicht, die von einem anderen Gerät geschlossen wurde, während diese Kasse offline war:** Die späten Verkäufe
  werden der richtigen Schicht hinzugefügt und ihr Bericht wird mit einem Abschnitt "späte Verkäufe" neu ausgegeben.

## Eine Kasse ist verloren oder gestohlen

Bitten Sie die Person, die Ihr Juwri-Konto verwaltet, die Kasse zu **sperren**. Beim nächsten Mal, wenn sie das Internet
erreicht, löscht sie alles, was sie enthält, nachdem sie alle Verkäufe gesendet hat, die sie kann. Erreicht sie das
Internet nie, löscht sie ihre Daten von selbst beim nächsten Start, sobald sie 3 Tage lang gesperrt war. Alles, was auf der
Kasse gespeichert ist, ist verschlüsselt und der Schlüssel verlässt nie das Gerät.

## Verwandte Seiten

- [Verkaufsstelle (POS)](../pos.md) — die Kasse, Zahlungen und die Schublade.
- [Mitarbeiterkonten](../staff.md) — Rollen, PINs und Berechtigungen pro Person.
- [Bestellungen verwalten](../orders.md) — wo synchronisierte Offline-Verkäufe erscheinen.
