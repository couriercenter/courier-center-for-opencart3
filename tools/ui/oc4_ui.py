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
KEYS = ['pickup_points', 'default_pickup_point', 'returns_enabled', 'returns_require_approval', 'returns_method_dropoff', 'returns_method_boxnow']


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
        page.on('dialog', lambda d: d.accept())
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
        for k, v in (('returns_enabled', '1'), ('returns_require_approval', '1'), ('returns_method_dropoff', '1'), ('returns_method_boxnow', '1')):
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
        # AJAX reload of the list (page 1 link) keeps the columns
        page.evaluate("() => $('#order').load($('#order a.page-link').attr('href') || window.location.href.replace('route=sale/order', 'route=sale/order.list'))")
        page.wait_for_timeout(2500)
        check('Τύπος' in ' '.join(page.locator('#order thead .cc-col-head').all_inner_texts()), 'columns again after an AJAX reload of the list')

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
