# E2E στον browser (κάθε release, και στα τρία τοπικά καταστήματα)

Καταστήματα:
- `http://localhost/oc3051/` (admin `/admin/`)
- `http://127.0.0.1:8738/` (admin `/manage/`)
- `http://127.0.0.1:8720/` (admin `/admin/`)

Admin: `admin` / `admin123`. Πριν ξεκινήσεις τρέξε το `tools/smoke/run.php <id> install`. Στο τέλος τρέξε `tools/smoke/run.php <id> cleanup`, που ακυρώνει όσα vouchers έφτιαξες.

## Admin
- [ ] Extensions → Extensions → Shipping: **μία** εγγραφή «Courier Center», χωρίς άλλες με «heading_title» ή κενό όνομα.
- [ ] Ρυθμίσεις: όλα τα πεδία, σωστά ελληνικά, version στον τίτλο. «Test & Auto-fill» → πράσινο μήνυμα. «Επιδιόρθωση εγκατάστασης» → λίστα ενεργειών. Αντιγραφή εντολής/URL cron.
- [ ] Αποθήκευση με επωνυμία αποστολέα `Ψ & Σ "Test"` → ξανά Edit → εμφανίζεται ακριβώς έτσι.
- [ ] Sales → Orders: κουμπί Manifest, μπάρα «Courier Center — επιλεγμένες», στήλες Μέθοδος / CC Voucher / CC Status (⚠️ στις flat).
- [ ] Παραγγελία → tab «Courier Center»:
  - Δημιουργία Voucher → AWB → PDF ανοίγει → Ανανέωση (2η φορά: μήνυμα «πρόσφατα») → Ακύρωση → φόρμα για νέο voucher.
  - Με τρεις παραγγελίες: Δημιουργία Vouchers → μήνυμα αποτελεσμάτων. Εκτύπωση Vouchers → ένα PDF.
- [ ] Manifest: «Χθες» και η φόρμα ημερομηνίας μένουν στη σελίδα. «Προβολή Manifest PDF».
- [ ] Οδηγίες και Αναφορά Προβλήματος ανοίγουν, με τη σωστή έκδοση.
- [ ] Κάνε logout σε άλλο tab και πάτα «Ανανέωση» στο tab παραγγελίας → μήνυμα «Η σύνδεση έληξε…».

## Checkout (κανονικό checkout του OpenCart, Guest)
- [ ] Βήμα «Delivery Method»:
  - Courier Center → εμφανίζεται το BOX NOW κάτω από τις επιλογές.
  - Flat Rate → χάνεται.
  - Courier Center → ξαναεμφανίζεται.
- [ ] BOX NOW → «Αυτόματη εύρεση» → ολοκλήρωση παραγγελίας → admin tab: «BOX NOW — Αυτόματη εύρεση Locker».
- [ ] BOX NOW → «Επιλογή από χάρτη» → διάλεξε locker → Continue → **Back** στο Delivery Method → Continue → Confirm → admin tab: το locker που διάλεξες.
- [ ] Ρύθμιση «Προεπιλεγμένο BOX NOW = Ναι»: με Courier Center εμφανίζεται τσεκαρισμένο και κλειδωμένο. Ξετσεκάρισμα → μήνυμα. Με Flat Rate → ξεκλειδώνει.
- [ ] Κονσόλα του browser (F12): **κανένα** σφάλμα JavaScript σε όλο το checkout.

## Μετά
- [ ] `tools/smoke/run.php <id> cleanup logcheck` → `error log: no new lines`.

## Αποτελέσματα 2026-10-07
- Αυτόματα (smoke, πραγματικό API): και τα 14 sections σε 3051 / 3038 / 3020, 79 ok, 0 failed σε κάθε ένα.
- Browser (χειροκίνητα): **δεν έγινε**. Το Claude in Chrome δεν ήταν συνδεδεμένο. Περιμένει τον χρήστη, ιδίως: ο χάρτης BOX NOW, το widget (JavaScript) στο checkout και η οπτική εμφάνιση του tab/της λίστας.
