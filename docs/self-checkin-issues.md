# QR/NFC Self-Checkin — Issue-Serie (Entwurf, nicht auf GitHub)

Ergebnis der Planungs-Session vom 2026-07-12. Elf AFK-taugliche Tracer-Bullet-Slices.
Labels bei späterer Veröffentlichung: `enhancement`, für S6–S11 zusätzlich `mobile-app`.
Flutter-Issues wie gehabt mit „Flutter:"-Präfix ins Server-Repo (`luflow/attendance`).

> Status 2026-07-13: Alle Slices S1–S11 sind implementiert
> (Server-Branch `feat/self-checkin-qr-nfc`, Flutter-Branch `feat/qr-nfc-self-checkin`).

## Kernentscheidungen (Kontext für alle Slices)

- QR/NFC ist reiner **Auslöser**, kein Anwesenheitsnachweis — kein Secret, keine Rotation.
- Self-Checkin ist **App-only** (Upsell-Funnel): Web-Self-Checkin wird hart durch eine
  „Hol dir die App"-Landeseite ersetzt (war nie verdrahtet).
- **Ein QR pro Instanz** für alle Events; Inhalt ist die HTTPS-URL der Landeseite.
  NFC-Tags tragen dieselbe URL als NDEF-Record.
- Custom Scheme: `nc-attendance://self-checkin?server=<url-encoded>`.
- Matching per Zeitfenster (konfigurierbar, Default 30 Min. vor Start bis Ende).
  1 Treffer → Sofort-Checkin; mehrere → Einfachauswahl-Picker; 0 → Hinweis auf nächsten Termin.
- Absager („no") dürfen einchecken; Scan-Pflicht, kein „Sofa-Button" im Event-Detail.
- Audit: `method` (`qr`|`nfc`) → `checkin_source` = `self_qr`/`self_nfc`, Methode im Audit-`meta`.
- Multi-Account: Server-URL aus QR matchen, Auto-Switch auf passenden Account,
  unbekannte Instanz → Login vorbefüllt.
- Organizer-Tools in der App für `canManageAppointments || canCheckin`.

## Hardware- und Plattform-Findings (Recherche 2026-07-12)

Quellen: [Apple Core NFC](https://developer.apple.com/documentation/corenfc),
[Background Tag Reading](https://developer.apple.com/documentation/corenfc/adding-support-for-background-tag-reading),
[Shop-NFC: NFC and iPhone](https://shopnfc.com/en/content/20-nfc-iphone),
[NFC21: On-Metal-Tags](https://nfc21.de/entry/on-metal-tags-so-funktionieren-ihre-nfc-tags-auch-auf-metall)

- **NFC-Sticker-Empfehlung:** NXP **NTAG213** (144 Bytes ≈ 130 Zeichen URL — reicht),
  Größe **≥ 25 mm** (Antennen-/Scanzuverlässigkeit), PVC/laminiert für außen.
  Auf **Metall-Untergrund zwingend On-Metal-Tags** (Ferrit-Schicht), normale Sticker
  sind dort funktionslos. Warnungen: keine „NTAG213-kompatiblen" Klone (iPhone-zickig),
  **kein MIFARE Classic 1K** (kein NDEF Type 2, inkompatibel mit iPhone).
- **iOS kann NDEF-Tags beschreiben:** iPhone 7+ mit iOS 13+, Core NFC — aber nur im
  Vordergrund über die System-Scan-Sheet. Der geplante Organizer-Write-Flow (S11)
  funktioniert damit auf beiden Plattformen.
- **iOS Background Tag Reading** (iPhone XS+): NDEF-Tags mit HTTPS-URL öffnen Safari
  ganz ohne App → Nicht-App-Nutzer landen automatisch auf der Landeseite (S4).
  Custom Schemes werden dabei NICHT unterstützt — bestätigt die Entscheidung,
  die HTTPS-URL (nicht `nc-attendance://`) auf Tags/QR zu schreiben.

---

## S1 — Server: Self-Checkin-API — `method`-Param (qr/nfc), Audit-Vermerk, Capability `selfCheckin`

**Typ:** AFK · **Labels:** enhancement

### What to build

Der Self-Checkin-Endpoint unterscheidet, wie der Checkin ausgelöst wurde. `POST /api/self-checkin`
nimmt einen Parameter `method` (`qr` | `nfc`) entgegen. Die Checkin-Quelle wird als `self_qr` bzw.
`self_nfc` gespeichert (ersetzt das bisherige pauschale `nfc`; kein Client nutzt den Endpoint bisher).
Das Audit-Event trägt die Methode im `meta`. Damit ist im Audit-Log erkennbar, dass es ein
Self-Checkin war (zusätzlich zu `actor == subject`) und über welchen Kanal.

Außerdem liefert `getCapabilities()` ein neues Flag `selfCheckin: true`, damit der Flutter-Client
das Feature gegen alte Server gaten kann (Abwesenheit = Feature aus).

### Acceptance criteria

- [x] `POST /api/self-checkin` akzeptiert optionales `method` (`qr`|`nfc`); ungültige Werte → 400
- [x] `checkin_source` wird `self_qr` / `self_nfc` gesetzt (Fallback ohne `method` definiert)
- [x] Audit-Event enthält die Methode im `meta`; Self-Checkin ist im Audit-Log als solcher erkennbar
- [x] `getCapabilities()` enthält `selfCheckin: true`, Psalm-Type `AttendanceCapabilities` aktualisiert
- [x] Unit-Tests für Service-Änderungen, `composer test:unit` grün
- [x] `composer openapi` ausgeführt, Spec-Dateien aktualisiert

### Blocked by

None — can start immediately.

---

## S2 — Server: Self-Checkin-Zeitfenster konfigurierbar

**Typ:** AFK · **Labels:** enhancement

### What to build

Das Zeitfenster, ab wann vor Terminbeginn Self-Checkin möglich ist (bisher fix 30 Minuten),
wird eine instanzweite Admin-Einstellung. Neuer Config-Wert mit Default 30, Eingabefeld in der
bestehenden Self-Checkin-Sektion der Admin-Settings, und die Fensterberechnung im
Self-Checkin-Service nutzt den konfigurierten Wert. Fensterende bleibt das Terminende.

### Acceptance criteria

- [x] Admin kann das Fenster (Minuten vor Start) in den App-Einstellungen ändern; Default 30
- [x] Self-Checkin-Service und Termin-Auflistung respektieren den konfigurierten Wert
- [x] Validierung sinnvoller Grenzen (0–1440 Minuten)
- [x] Unit-Tests, `composer test:unit` grün

### Blocked by

None — can start immediately.

---

## S3 — Server: „Nächster Termin"-Info im Self-Checkin-Appointments-Endpoint

**Typ:** AFK · **Labels:** enhancement

### What to build

`GET /api/self-checkin/appointments` liefert zusätzlich zum Treffer-Array den nächsten
anstehenden, für den User sichtbaren Termin samt Zeitpunkt, ab dem dessen Checkin-Fenster
öffnet. Der Client kann damit bei 0 Treffern anzeigen: „Gerade kein Termin — als Nächstes:
Training, Checkin ab 18:30".

### Acceptance criteria

- [x] Response enthält den nächsten anstehenden Termin + Fensterbeginn (nullable)
- [x] Fensterbeginn basiert auf dem konfigurierten Fenster aus S2
- [x] Psalm-Types in `ResponseDefinitions.php` ergänzt, `composer openapi` ausgeführt
- [x] Unit-Tests, `composer test:unit` grün

### Blocked by

S2 (konfigurierbares Fenster).

---

## S4 — Web: „Hol dir die App"-Landeseite ersetzt Web-Self-Checkin (harter Cut)

**Typ:** AFK · **Labels:** enhancement

### What to build

Die Web-Self-Checkin-Seite (`GET /self-checkin`) wird durch eine reine Upsell-/Landeseite
ersetzt: Store-Badges für App Store und Google Play (Links existieren bereits im Web-Frontend,
`src/utils/mobileApp.js`) plus ein Button „In der App öffnen", der
`nc-attendance://self-checkin?server=<instanz-url>` aufruft. Die bisherige
Web-Checkin-Funktionalität auf dieser Seite entfällt ersatzlos (war nirgends verlinkt).

Diese URL ist zugleich das, was QR-Codes und NFC-Tags enthalten: Kamera-Scan ohne App
landet hier; mit installierter App öffnet der Button die App direkt. Bonus (verifiziert):
iPhone XS+ öffnet NFC-Tags mit HTTPS-URL per Background Tag Reading ganz ohne App in
Safari — der Upsell-Funnel funktioniert also auch beim beiläufigen Dranhalten des Telefons.

### Acceptance criteria

- [x] `GET /self-checkin` zeigt Landeseite mit beiden Store-Badges und „In der App öffnen"-Button
- [x] Button ruft das Custom Scheme mit korrekt encodierter Server-URL auf
- [x] Kein Web-Checkin mehr möglich; alte Web-Checkin-UI und zugehöriger Code entfernt
- [x] Seite funktioniert ohne Login (PublicPage)
- [ ] Changelog-Eintrag zum Entfall des Web-Self-Checkins (beim Release)

### Blocked by

None — can start immediately. (Definiert den Scheme-Kontrakt, den S8 konsumiert.)

---

## S5 — Web: Admin-Settings — QR-Code anzeigen, drucken, URL kopieren

**Typ:** AFK · **Labels:** enhancement

### What to build

Die Self-Checkin-Sektion der Admin-Settings zeigt den instanzweiten QR-Code (encodiert die
Landeseiten-URL aus S4), bietet einen Download/Druck als aushängbares Dokument
(„Hier scannen zum Einchecken" + QR) und ein Copy-Feld mit der URL, um NFC-Tags mit einer
beliebigen Writer-App zu beschreiben.

Zusätzlich zeigt die Sektion eine kompakte **NFC-Sticker-Kaufempfehlung** (aus der Recherche,
siehe Kontext-Block oben): NXP NTAG213, ≥ 25 mm, auf Metall-Untergrund On-Metal-Tags,
Warnung vor MIFARE-Classic- und Klon-Tags. Herstellerneutral formulieren (Chip-Typ, keine
Marken-/Shop-Links), englische Strings für Transifex.

### Acceptance criteria

- [x] QR-Code wird in den Admin-Settings angezeigt und encodiert die Landeseiten-URL
- [x] Download-Funktion (PNG)
- [x] URL-Copy-Feld mit Hinweis zur NFC-Beschreibung
- [x] Kaufempfehlungs-Hinweis sichtbar: NXP NTAG213, ≥ 25 mm, On-Metal bei Metall-Untergrund, Warnung vor MIFARE Classic/Klonen
- [x] Fenster-Einstellung (Minuten vor Start) in derselben Sektion
- [x] `npm run build` erfolgreich, englische `t()`-Strings gemäß Guidelines

### Blocked by

S4 (URL-Semantik der Landeseite).

---

## S6 — Flutter: QR-Scan → Self-Checkin (Kern-Flow)

**Typ:** AFK · **Labels:** enhancement, mobile-app

### What to build

Der komplette Teilnehmer-Flow: Scan-Icon in der AppBar des Termine-Screens öffnet einen
Scanner-Screen (`mobile_scanner`). Nach erkanntem QR (Landeseiten-URL der eigenen Instanz)
lädt die App die checkin-fähigen Termine:

- genau 1 Treffer → sofort einchecken (`method=qr`), Erfolgsscreen mit Terminname
- mehrere Treffer → Picker „Zu welchem Termin willst du einchecken?" (Einfachauswahl,
  bereits eingecheckte Termine markiert/nicht wählbar)
- 0 Treffer → Hinweisscreen mit nächstem anstehendem Termin und Fensterbeginn

Sichtbarkeit des Scan-Icons gated auf Server-Capability `selfCheckin` **und** User-Permission
`canSelfCheckin`. Kein Checkin-Button im Termin-Detail — Scan ist Pflicht.

### Acceptance criteria

- [x] Scan-Icon nur sichtbar bei Capability `selfCheckin` + Permission `canSelfCheckin`
- [x] Alle drei Treffer-Fälle umgesetzt (Sofort-Checkin / Picker / Hinweis auf nächsten Termin)
- [x] Checkin sendet `method=qr`; Erfolgsscreen zeigt Termin und eingecheckten Account
- [x] Erneuter Scan nach Checkin zeigt „schon eingecheckt" statt Fehler
- [x] QR fremder/unbekannter URLs wird sauber abgewiesen (verständliche Meldung)

### Blocked by

S1 (Capability + `method`-Param). S3 optional — ohne S3 degradiert der 0-Treffer-Screen zum generischen Hinweis.

---

## S7 — Flutter: NFC-Lesen im Scanner-Screen

**Typ:** AFK · **Labels:** enhancement, mobile-app

### What to build

Der Scanner-Screen aus S6 kann zusätzlich NFC-Tags lesen (`nfc_manager`): auf Android lauscht
eine NFC-Session parallel zur laufenden Kamera („…oder Telefon an den NFC-Tag halten"),
auf iOS startet ein Button die System-Scan-Sheet. Gelesene NDEF-URL durchläuft denselben
Matching-Flow wie ein QR-Scan, der Checkin wird mit `method=nfc` gesendet.

### Acceptance criteria

- [x] Android: NFC-Tag-Erkennung parallel zum Kamera-Scanner, ohne den QR-Weg zu stören
- [x] iOS: Button öffnet die NFC-Scan-Sheet; Entitlements + `NSNFCReaderUsageDescription` konfiguriert
- [x] NFC-Checkin sendet `method=nfc`; identischer Treffer-Flow wie S6
- [x] Geräte ohne NFC: Hinweis/Ausblendung statt Fehler

### Blocked by

S6.

---

## S8 — Flutter: `nc-attendance://`-Deeplink + Account-Matching

**Typ:** AFK · **Labels:** enhancement, mobile-app

### What to build

Die App registriert das Custom Scheme `nc-attendance://` (Android Manifest + iOS Info.plist,
Handling via `app_links`) und verarbeitet `nc-attendance://self-checkin?server=…`
aus dem „In der App öffnen"-Button der Landeseite. Der `server`-Parameter wird gegen die
gespeicherten Accounts gematcht:

- aktiver Account passt → direkt in den Checkin-Flow (S6)
- anderer gespeicherter Account passt → automatischer Account-Switch, transparent im Erfolgsscreen
- kein Account passt → Server-Connect-Screen mit vorbefüllter Instanz-URL (Onboarding-Pfad)

Dasselbe Matching gilt für in-App gescannte QR-Codes fremder Instanzen.

### Acceptance criteria

- [x] Scheme auf Android und iOS registriert; Deeplink öffnet die App in den Checkin-Flow
- [x] Alle drei Account-Fälle umgesetzt (aktiv / Auto-Switch / Login vorbefüllt)
- [x] Erfolgsscreen zeigt nach Auto-Switch klar, mit welchem Account auf welcher Instanz eingecheckt wurde
- [x] Deeplink bei kalter und warmer App-Instanz funktionsfähig

### Blocked by

S6 (Checkin-Flow); Scheme-Kontrakt gemeinsam mit S4.

---

## S9 — Flutter: OS-App-Shortcuts zum Scanner

**Typ:** AFK · **Labels:** enhancement, mobile-app

### What to build

Long-Press aufs App-Icon bietet einen Shortcut „Einchecken" (Android App Shortcuts +
iOS Quick Actions via `quick_actions`), der direkt den Scanner-Screen öffnet.

### Acceptance criteria

- [x] Shortcut auf Android und iOS vorhanden und öffnet direkt den Scanner
- [x] Kein Shortcut-Crash, wenn kein Account eingerichtet ist (Fallback auf normalen App-Start)

### Blocked by

S6.

---

## S10 — Flutter: Organizer-Tools — QR anzeigen und teilen

**Typ:** AFK · **Labels:** enhancement, mobile-app

### What to build

Neue Settings-Sektion „Self-Checkin" in der App, sichtbar für User mit
`canManageAppointments` **oder** `canCheckin`. Sie rendert den instanzweiten QR-Code
(Landeseiten-URL des aktiven Accounts) groß auf dem Bildschirm (z. B. Tablet am Eingang)
und bietet Teilen/Exportieren als Bild.

### Acceptance criteria

- [x] Sektion nur sichtbar bei `canManageAppointments || canCheckin` (+ Capability `selfCheckin`)
- [x] QR-Vollbild-Anzeige
- [x] Teilen/Export als Bild funktioniert
- [x] QR encodiert exakt dieselbe URL wie die Web-Admin-Settings (S5)

### Blocked by

None — can start immediately (URL-Route existiert bereits).

---

## S11 — Flutter: Organizer-Tools — NFC-Tag beschreiben

**Typ:** AFK · **Labels:** enhancement, mobile-app

### What to build

In der Organizer-Sektion aus S10: Button „NFC-Tag beschreiben" startet einen Write-Flow
(`nfc_manager`), der die Landeseiten-URL als NDEF-URL-Record auf ein Tag schreibt.
iOS nutzt die System-Scan-Sheet (verifiziert: NDEF-Schreiben geht auf iPhone 7+ mit
iOS 13+, nur Vordergrund), Android eine Vordergrund-Session mit „Tag ans Telefon
halten"-Anleitung. Ziel-Hardware laut Kaufempfehlung: NXP NTAG213 (144 Bytes reichen
für die URL); inkompatible Tags (z. B. MIFARE Classic) müssen sauber abgefangen werden.

### Acceptance criteria

- [x] Tag-Beschreiben funktioniert auf Android und iOS; beschriebenes Tag löst den Teilnehmer-Flow aus
- [x] Schreibgeschützte/inkompatible Tags erzeugen verständliche Fehlermeldung
- [x] Erfolgsbestätigung mit Hinweis, das Tag testweise zu scannen
- [x] Geräte ohne NFC: Button ausgeblendet oder mit Hinweis deaktiviert

### Blocked by

S10 (Sektion); teilt NFC-Setup (Entitlements/Dependencies) mit S7.
