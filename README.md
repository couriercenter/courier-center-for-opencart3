# Courier Center για OpenCart 3

Δημιουργία vouchers, BOX NOW, παρακολούθηση αποστολών και Manifest της Courier Center μέσα από το admin του OpenCart.

## Απαιτήσεις
- OpenCart **3.0.2.0 έως 3.0.5.x**
- PHP **7.2 ή νεότερη** (έως 8.5) με τα extensions `curl` και `zip`
- Λογαριασμός Courier Center με στοιχεία API (User Alias, Credential Value, API Key, Billing Account)

## Εγκατάσταση
1. Admin → **Extensions → Installer** → Upload → `courier_center_oc3.ocmod.zip`.
2. **Extensions → Extensions** → τύπος **Shipping** → «Courier Center» → **Install (+)** → **Edit**.
3. Συμπλήρωσε τα στοιχεία API και πάτα **«Test & Auto-fill»**. Ελέγχει τα στοιχεία και συμπληρώνει τον αποστολέα.
4. Όρισε κόστος, BOX NOW και τις υπόλοιπες ρυθμίσεις και πάτα **Αποθήκευση**.
5. **Cron** (αυτόματη ενημέρωση κατάστασης): στις ρυθμίσεις αντέγραψε
   - την **εντολή** για το cPanel → Cron Jobs, κάθε 2 ώρες, **ή**
   - το **URL με μυστικό κλειδί**, για hosting χωρίς cron ή για υπηρεσίες όπως το cron-job.org.

Δεν χρειάζεται FTP, SQL ή «Refresh modifications».

## Ενημέρωση
- Ρυθμίσεις → **«Έλεγχος για ενημερώσεις» → «Ενημέρωση τώρα»**. Γίνεται αυτόματο backup και επαναφορά αν κάτι αποτύχει.
- Εναλλακτικά, ανέβασε το νέο zip από Extensions → Installer. Η εγκατάσταση επιδιορθώνεται μόνη της στην επόμενη σελίδα του admin.

## Αν κάτι δεν δουλεύει
- Ρυθμίσεις → **«Επιδιόρθωση εγκατάστασης»**. Δεν σβήνει δεδομένα.
- Βλέπεις «Notice» / «Warning» στις σελίδες; Είναι μηνύματα PHP που εμφανίζει το OpenCart όταν είναι ενεργό το System → Settings → Server → **Display Errors**. Σε κατάστημα σε λειτουργία απενεργοποίησέ το. Τα μηνύματα συνεχίζουν να γράφονται στο Error Log.
- Αν η σελίδα ρυθμίσεων γράφει ότι χρειάζεται νεότερη PHP, ζήτα PHP 7.2+ από τον πάροχο φιλοξενίας.
- Ρυθμίσεις → **«Αναφορά Προβλήματος»**: το μήνυμα φτάνει στην ομάδα της Courier Center.

## Απεγκατάσταση
Extensions → Shipping → Uninstall. Τις ρυθμίσεις τις σβήνει το OpenCart. Το ιστορικό αποστολών και το κλειδί cron **μένουν**, ώστε μια νέα εγκατάσταση να συνεχίσει από εκεί που ήταν.

## Ανάπτυξη

```bash
/c/xampp/php/php.exe tools/setup-php.php        # μία φορά: portable PHP 7.0/7.2/7.4/8.1/8.5
/c/xampp/php/php.exe tools/lint.php             # σύνταξη PHP/Twig για όλες τις εκδόσεις
/c/xampp/php/php.exe tools/test-all.php         # tests σε PHP 7.2, 7.4, 8.1, 8.2, 8.5
/c/xampp/php/php.exe tools/build.php            # dist/courier_center_oc3.ocmod.zip
/c/xampp/php/php.exe tools/smoke/run.php 3051   # E2E σε τοπικό OpenCart 3 (βλ. tools/env)
```

Το library (`upload/system/library/couriercenter/CC*.php`) είναι **κοινό με το repo του OpenCart 4**: κάθε αλλαγή του περνά και εκεί (`tools/sync-library.php`).
