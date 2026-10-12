"""
Headless Chrome check of the OpenCart 4 extension's JavaScript (1.1.0) on the local OC4 shop.
Needs: the shop at BASE (PHP built-in server), the temporary admin ccsmoke/ccsmoke123, settings with the test account.
Uses two copies of order #11 (removed at the end); every voucher it makes is voided; settings it touches are restored.
Usage: python tools/ui/oc4_ui.py [base_url]
"""
import json, os, re, subprocess, sys
from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8744/opencart4/'
DB = 'opencart4'
MYSQL = 'C:/xampp/mysql/bin/mysql.exe'
PHP = 'C:/xampp/php/php.exe'
ok, fail, awbs, clones = 0, [], [], []
KEYS = ['pickup_points', 'default_pickup_point', 'returns_enabled', 'returns_require_approval', 'returns_method_dropoff', 'returns_method_boxnow', 'returns_method_pickup']


def check(cond, what):
    global ok
    if cond:
        ok += 1
        print('  ok   ' + what)
    else:
        fail.append(what)
        print('  FAIL ' + what)


def sql(q):
    out = subprocess.run([MYSQL, '-uroot', DB, '-N', '-e', q], capture_output=True, text=True, encoding='utf-8')
    if out.returncode:
        raise RuntimeError(out.stderr + ' :: ' + q)
    return [line.split('\t') for line in out.stdout.strip().splitlines() if line]


def clone(over):
    cols = [r[0] for r in sql("SHOW COLUMNS FROM oc_order") if r[0] not in ('order_id', 'date_added', 'date_modified')]
    sel = ', '.join(over.get(c, '`%s`' % c) for c in cols)
    sql("INSERT INTO oc_order (%s, date_added, date_modified) SELECT %s, NOW(), NOW() FROM oc_order WHERE order_id = 11" % (', '.join('`%s`' % c for c in cols), sel))
    oid = sql("SELECT MAX(order_id) FROM oc_order")[0][0]
    sql("INSERT INTO oc_order_product (order_id, product_id, master_id, name, model, quantity, price, total, tax, reward) SELECT %s, product_id, master_id, name, model, 2, price, total, tax, reward FROM oc_order_product WHERE order_id = 11" % oid)
    clones.append(oid)
    return oid


ROOT = 'C:/xampp/htdocs/opencart4/'
CONFIGS = {f: open(ROOT + f, encoding='utf-8').read() for f in ('config.php', 'admin/config.php')}
for f, txt in CONFIGS.items():
    # The shop's URLs point at localhost:80 (taken by another local server): served on BASE for the test, restored at the end.
    open(ROOT + f, 'w', encoding='utf-8', newline='').write(txt.replace('http://localhost/opencart4/', BASE))
backup = sql("SELECT `key`, value, serialized FROM oc_setting WHERE `key` IN (%s)" % ', '.join("'shipping_courier_center_%s'" % k for k in KEYS))


def main():
    with sync_playwright() as p:
        br = p.chromium.launch(channel='chrome', headless=True)
        ctx = br.new_context()
        page = ctx.new_page()
        dialogs = []
        page.on('dialog', lambda d: (dialogs.append(d.message), d.accept()))
        errors = []
        page.on('pageerror', lambda e: errors.append(str(e)))

        print('== admin login')
        page.goto(BASE + 'admin/index.php?route=common/login')
        page.fill('#input-username', 'ccsmoke')
        page.fill('#input-password', 'ccsmoke123')
        page.click('#form-login button[type=submit]')
        page.wait_for_url(re.compile('user_token='))
        token = re.search(r'user_token=([A-Za-z0-9]+)', page.url).group(1)
        A = lambda route, extra='': BASE + 'admin/index.php?route=' + route + '&user_token=' + token + extra
        check(bool(token), 'logged in')

        print('== settings: pickup point rows')
        sql("DELETE FROM oc_setting WHERE `key`='shipping_courier_center_pickup_points'")
        page.goto(A('extension/couriercenter/shipping/courier_center'))
        page.click('#cc-pt-add')
        row = page.locator('#cc-points-table tbody tr').last
        row.locator('.cc-pt-code').fill('100-0120-0003')
        row.locator('input[name$="[label]"]').fill('Τρίτο σημείο')
        row.locator('.cc-pt-autofill').click()
        page.wait_for_function("document.getElementById('cc-pt-status').textContent.indexOf('100-0120-0003') !== -1 && document.getElementById('cc-pt-status').textContent.indexOf('Δημιουργία') === -1", timeout=90000)
        check('✓' in page.inner_text('#cc-pt-status') and row.locator('.cc-pt-name').input_value() != '', 'row autofill: ' + page.inner_text('#cc-pt-status'))
        for k, v in (('returns_enabled', '1'), ('returns_require_approval', '1'), ('returns_method_dropoff', '1'), ('returns_method_boxnow', '1'), ('returns_method_pickup', '1')):
            page.select_option('select[name="shipping_courier_center_%s"]' % k, v)
        page.click('button[form="form-courier-center"]')
        page.wait_for_load_state('load')
        saved = sql("SELECT value FROM oc_setting WHERE `key`='shipping_courier_center_pickup_points'")
        pts = json.loads(saved[0][0]) if saved else []
        check(len(pts) == 1 and pts[0]['code'] == '100-0120-0003' and pts[0]['name'], 'point saved with sender data')
        cred = sql("SELECT value FROM oc_setting WHERE `key`='shipping_courier_center_credential_value'")
        check(cred and cred[0][0] != '', 'credential kept after a save from the browser (empty password field)')

        o_norm = clone({'telephone': "'6912345678'"})
        o_bn = clone({'telephone': "'6912345678'"})

        print('== order panel (order %s)' % o_norm)
        page.goto(A('sale/order.info', '&order_id=' + o_norm))
        page.click('a[href="#tab-courier-center"]')
        sel = page.locator('#cc-pickup-point')
        check(sel.count() == 1 and sel.locator('option').count() == 2, 'point select in the tab')
        sel.select_option('100-0120-0003')
        page.click('[data-cc-action="create"]')
        page.wait_for_load_state('load')
        page.wait_for_timeout(1500)
        r = sql("SELECT voucher_number, pickup_point FROM oc_cc_shipments WHERE order_id=" + o_norm)
        if r:
            awbs.append(r[0][0])
        check(r and r[0][1] == '100-0120-0003', 'voucher %s from 100-0120-0003' % (r[0][0] if r else '-'))
        check(page.locator('#tab-courier-center').count() == 1 and '100-0120-0003' in page.inner_text('#cc-panel'), 'tab shows the point after the reload')
        page.click('a[href="#tab-courier-center"]')
        page.click('[data-cc-action="void"]')
        page.wait_for_load_state('load')
        page.wait_for_timeout(1500)
        check(sql("SELECT is_voided FROM oc_cc_shipments WHERE order_id=" + o_norm)[0][0] == '1', 'void from the tab')
        page.click('a[href="#tab-courier-center"]')
        page.click('[data-cc-action="create"]')
        page.wait_for_load_state('load')
        page.wait_for_timeout(1500)
        r = sql("SELECT voucher_number, is_voided FROM oc_cc_shipments WHERE order_id=" + o_norm)
        if r and r[0][1] == '0':
            awbs.append(r[0][0])
        check(r and r[0][1] == '0', 'new voucher after the void, from the tab')

        res = page.evaluate("""async (a) => { const r = await fetch(a.url, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams({order_id: a.id, boxnow: 1}).toString()}); return r.json(); }""",
                            {'url': A('extension/couriercenter/shipping/courier_center.createVoucher'), 'id': o_bn})
        if res.get('success'):
            awbs.append(res['voucher'])
        check(res.get('success'), 'BOX NOW voucher: ' + json.dumps(res, ensure_ascii=False)[:150])

        print('== order list')
        page.goto(A('sale/order'))
        page.wait_for_selector('#cc-bulk-bar')
        check(page.locator('#cc-bulk-point option').count() == 2, 'bulk point select')
        check(page.locator('#cc-manifest-btn').count() == 1 and page.locator('#cc-returns-btn').count() == 1, 'Manifest + Επιστροφές buttons')
        heads = ' '.join(page.locator('#order thead .cc-col-head').all_inner_texts())
        check('Τύπος' in heads, 'columns: ' + heads)
        for oid in (o_norm, o_bn):
            page.check('#order input[name="selected[]"][value="%s"]' % oid)
        page.evaluate("() => { window.__opened = []; window.open = function (u) { window.__opened.push(String(u)); return null; }; }")
        page.click('#cc-bulk-bar button:has-text("Εκτύπωση Vouchers")')
        note = page.locator('#cc-print-note')
        check(note.is_visible(), 'note for the BOX NOW PDF')
        note.locator('button').click()
        opened = page.evaluate("() => window.__opened") or []
        check(len(opened) == 2 and 'bulkPrint&' in opened[0] and 'bulkPrintBoxnow' in opened[1], 'print: 2 PDFs')
        pdf = page.evaluate("async (u) => (await (await fetch(u, {credentials: 'same-origin'})).text()).slice(0, 4)", opened[1] if len(opened) > 1 else A('x'))
        check(pdf == '%PDF', 'BOX NOW PDF downloads')
        page.uncheck('#order input[name="selected[]"][value="%s"]' % o_bn)
        n_dialogs = len(dialogs)
        page.click('#cc-bulk-bar button:has-text("Εκτύπωση BOX NOW")')
        check(len(dialogs) > n_dialogs and 'BOX NOW voucher' in dialogs[-1], 'BOX NOW-only print with no BOX NOW order selected → alert')
        page.check('#order input[name="selected[]"][value="%s"]' % o_bn)
        page.evaluate("() => { window.__opened = []; }")
        page.click('#cc-bulk-bar button:has-text("Εκτύπωση BOX NOW")')
        opened_bn = page.evaluate("() => window.__opened") or []
        check(len(opened_bn) == 1 and 'bulkPrintBoxnow' in opened_bn[0] and o_bn in opened_bn[0], 'BOX NOW-only print opens just the BOX NOW PDF')
        # AJAX reload of the list (page 1 link) keeps the columns
        page.evaluate("() => $('#order').load($('#order a.page-link').attr('href') || window.location.href.replace('route=sale/order', 'route=sale/order.list'))")
        page.wait_for_timeout(2500)
        check('Τύπος' in ' '.join(page.locator('#order thead .cc-col-head').all_inner_texts()), 'columns again after an AJAX reload of the list')

        print('== customer return form (order %s)' % o_norm)
        email = 'ui-returns-oc4@example.com'
        sql("DELETE FROM oc_customer WHERE email='%s'" % email)
        pw = subprocess.run([PHP, '-r', 'echo password_hash("ret123", PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout.strip()
        sql("INSERT INTO oc_customer SET customer_group_id=1, store_id=0, language_id=1, firstname='UI', lastname='OC4', email='%s', telephone='6912345678', password='%s', custom_field='', newsletter=0, ip='', status=1, safe=0, token='', code='', date_added=NOW()" % (email, pw))
        cid = sql("SELECT customer_id FROM oc_customer WHERE email='%s'" % email)[0][0]
        done = json.loads(sql("SELECT value FROM oc_setting WHERE `key`='config_complete_status'")[0][0])[0]
        sql("UPDATE oc_order SET customer_id=%s, email='%s', order_status_id=%s WHERE order_id=%s" % (cid, email, done, o_norm))
        sql("INSERT INTO oc_order_history SET order_id=%s, order_status_id=%s, notify=0, comment='ui', date_added=NOW()" % (o_norm, done))
        cctx = br.new_context()
        cpage = cctx.new_page()
        cdialogs = []
        cpage.on('dialog', lambda d: (cdialogs.append(d.message), d.accept()))
        cpage.on('pageerror', lambda e: errors.append('customer: ' + str(e)))
        cpage.goto(BASE + 'index.php?route=account/login&language=en-gb')
        cpage.fill('#input-email', email)
        cpage.fill('#input-password', 'ret123')
        cpage.click('#form-login button[type=submit]')
        cpage.wait_for_url(re.compile('customer_token='), timeout=20000)
        ctok = re.search(r'customer_token=([A-Za-z0-9]+)', cpage.url).group(1)
        cpage.goto(BASE + 'index.php?route=account/order&language=en-gb&customer_token=' + ctok)
        check(cpage.locator('a.cc-return-btn').count() >= 1, 'customer orders list shows «Αίτημα Επιστροφής»')
        cpage.goto(BASE + 'index.php?route=extension/couriercenter/shipping/courier_center_returns&language=en-gb&customer_token=' + ctok + '&order_id=' + o_norm)
        cpage.wait_for_load_state('load')
        check(cpage.locator('#cc-return-form').count() == 1, 'customer return form renders')

        print('== Pickup JS (customer form, nothing submitted)')
        form_url = cpage.url
        check(not cpage.locator('#cc-pickup').is_visible(), 'Pickup fields hidden for Drop Off')
        cpage.check('input[name="method"][value="COURIER_CENTER_PICKUP"]')
        check(cpage.locator('#cc-pickup').is_visible() and not cpage.locator('#cc-sizes').is_visible(), 'Pickup shows its fields')
        check(cpage.locator('#cc-pickup-date option').count() == 10, 'ten pickup dates offered')
        cpage.select_option('#cc-pickup-from', '11:00')
        dis = cpage.evaluate("() => Array.from(document.querySelectorAll('#cc-pickup-to option')).filter(o => o.disabled).map(o => o.value)")
        check(dis == ['13:00', '14:00'], '«Έως» offers only hours ≥ Από + 4 (disabled: ' + ','.join(dis) + ')')
        cpage.locator('input[name^="qty["]').first.fill('1')
        cpage.click('#cc-submit')
        check(cdialogs and 'δύο άκρα' in cdialogs[-1], 'only «Από» → alert: ' + (cdialogs[-1] if cdialogs else '-'))
        cpage.select_option('#cc-pickup-to', '15:00')
        cpage.fill('#cc-pickup-postcode', '123')
        cpage.click('#cc-submit')
        check('5 ψηφία' in cdialogs[-1], 'bad postcode → alert')
        cpage.goto(form_url)
        cpage.wait_for_load_state('load')
        sql("DELETE FROM oc_customer WHERE customer_id=" + cid)
        cctx.close()
        sql("INSERT INTO oc_cc_return_requests SET request_uid='uinotice%s', reference_token='RRUI%s', order_id=%s, customer_id=0, status='pending_approval', method='COURIER_CENTER_DROPOFF', items='[]', created_at=NOW(), updated_at=NOW()" % (o_norm, o_norm, o_norm))

        print('== admin «Νέα επιστροφή» JS + notice (nothing submitted)')
        page.goto(A('common/dashboard'))
        check(page.locator('#cc-returns-notice').count() == 1, 'dashboard notice while a request waits')
        page.goto(A('extension/couriercenter/shipping/courier_center_returns.add', '&order_id=' + o_norm))
        page.wait_for_load_state('load')
        check(page.locator('#cc-return-form').count() == 1 and page.locator('input[name="method"]').count() == 3, '«Νέα επιστροφή» form with the 3 methods')
        page.check('input[name="method"][value="COURIER_CENTER_PICKUP"]')
        check(page.locator('#cc-pickup').is_visible(), 'admin form: Pickup shows its fields')
        page.check('input[name="method"][value="BOXNOW"]')
        check(page.locator('#cc-sizes').is_visible() and not page.locator('#cc-pickup').is_visible(), 'admin form: BOX NOW shows the sizes')
        page.click('#cc-submit')
        check(dialogs and 'τουλάχιστον ένα τεμάχιο' in dialogs[-1], 'admin form: no quantity → alert')

        print('== manifest form')
        page.goto(A('extension/couriercenter/shipping/courier_center_manifest'))
        page.select_option('select[name="account"]', '100-0120-0003')
        page.click('form button[type=submit]')
        page.wait_for_load_state('load')
        check('courier_center_manifest' in page.url and 'account=100-0120-0003' in page.url and 'user_token=' in page.url, '«Ενημέρωση» stays on the page with the filter')
        check(not errors, 'no JavaScript errors: ' + '; '.join(errors[:3]))
        br.close()


try:
    main()
finally:
    for f, txt in CONFIGS.items():
        open(ROOT + f, 'w', encoding='utf-8', newline='').write(txt)
    print('config.php restored:', all(open(ROOT + f, encoding='utf-8').read() == t for f, t in CONFIGS.items()))
    if awbs:
        out = subprocess.run([PHP, 'tools/ui/void.php'] + awbs, capture_output=True, text=True, encoding='utf-8')
        print(out.stdout.strip())
    for oid in clones:
        for t in ('order', 'order_product', 'order_history', 'order_total', 'cc_shipments', 'cc_return_requests'):
            sql("DELETE FROM oc_%s WHERE order_id=%s" % (t, oid))
    sql("DELETE FROM oc_setting WHERE `key` IN (%s)" % ', '.join("'shipping_courier_center_%s'" % k for k in KEYS))
    for k, v, ser in backup:
        sql("INSERT INTO oc_setting SET store_id=0, code='shipping_courier_center', `key`='%s', value='%s', serialized=%s" % (k, v.replace("'", "\\'"), ser))
    print('cleanup: %d order copies removed, settings restored' % len(clones))
    print('\n[UI OC4] %d ok, %d failed' % (ok, len(fail)))
    for f in fail:
        print('  - ' + f)
