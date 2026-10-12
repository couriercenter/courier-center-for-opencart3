"""
Headless Chrome check of the OpenCart 3 extension's JavaScript (1.1.0) on a local test shop.
Prepare first:  php tools/smoke/run.php 3041 install points   (settings + one sub-code, orders with voided vouchers)
Usage: python tools/ui/oc3_ui.py [base_url] [db]       (default http://127.0.0.1:8741/  cc_oc3041)
Real API: one normal + one BOX NOW voucher are created and printed; tools/ui/void.php voids them at the end.
"""
import json, re, subprocess, sys
from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8741/'
DB = sys.argv[2] if len(sys.argv) > 2 else 'cc_oc3041'
MYSQL = 'C:/xampp/mysql/bin/mysql.exe'
PHP = 'C:/xampp/php/php.exe'
ok, fail, awbs = 0, [], []


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
    return [line.split('\t') for line in out.stdout.strip().splitlines() if line]


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
        page.fill('input[name=username]', 'admin')
        page.fill('input[name=password]', 'admin123')
        page.click('button[type=submit]')
        page.wait_for_url(re.compile('user_token='))
        token = re.search(r'user_token=([A-Za-z0-9]+)', page.url).group(1)
        A = lambda route, extra='': BASE + 'admin/index.php?route=' + route + '&user_token=' + token + extra
        check(bool(token), 'logged in')

        print('== settings: pickup point rows')
        sql("DELETE FROM oc_setting WHERE `key`='shipping_courier_center_pickup_points'")
        page.goto(A('extension/shipping/courier_center'))
        rows0 = page.locator('#cc-points-table tbody tr').count()
        page.click('#cc-pt-add')
        row = page.locator('#cc-points-table tbody tr').last
        row.locator('.cc-pt-code').fill('100-0120-0003')
        row.locator('input[name$="[label]"]').fill('Τρίτο σημείο')
        row.locator('.cc-pt-autofill').click()
        page.wait_for_function("document.getElementById('cc-pt-status').textContent.indexOf('100-0120-0003') !== -1 && document.getElementById('cc-pt-status').textContent.indexOf('Δημιουργία') === -1", timeout=90000)
        status = page.inner_text('#cc-pt-status')
        name = row.locator('.cc-pt-name').input_value()
        check('✓' in status and name != '', 'row autofill fills the hidden fields: ' + status + ' / ' + name)
        page.click('#cc-ra-add')
        check(page.locator('#cc-ra-table tbody tr').count() >= 1, 'returns account row added')
        page.locator('#cc-ra-table tbody tr').last.locator('.cc-ra-remove').click()
        page.click('button[form="form-courier-center"]')
        page.wait_for_load_state('load')
        saved = sql("SELECT value FROM oc_setting WHERE `key`='shipping_courier_center_pickup_points'")
        pts = json.loads(saved[0][0]) if saved else []
        check(rows0 + 1 == len(pts) and any(p['code'] == '100-0120-0003' and p['name'] for p in pts), 'saved %d points incl. 0003 with sender data' % len(pts))
        n_opts = 1 + len(pts)

        oids = [r[0] for r in sql("SELECT order_id FROM oc_cc_shipments WHERE is_voided=1 ORDER BY order_id DESC LIMIT 2")]
        o_norm, o_bn = oids[0], oids[1]

        print('== order panel: create from a chosen point (order %s)' % o_norm)
        page.goto(A('sale/order/info', '&order_id=' + o_norm))
        page.click('a[href="#tab-courier-center"]')
        sel = page.locator('#cc-pickup-point')
        check(sel.count() == 1 and sel.locator('option').count() == n_opts, 'point select with %d points' % n_opts)
        sel.select_option('100-0120-0003')
        page.click('[data-cc-action="create"]')
        page.wait_for_load_state('load')
        page.wait_for_timeout(1500)
        r = sql("SELECT voucher_number, pickup_point, is_voided FROM oc_cc_shipments WHERE order_id=" + o_norm)
        if r and r[0][2] == '0':
            awbs.append(r[0][0])
        check(r and r[0][1] == '100-0120-0003' and r[0][2] == '0', 'voucher %s from 100-0120-0003' % (r[0][0] if r else '-'))
        txt = page.inner_text('#cc-panel') if page.locator('#cc-panel').count() else ''
        check('100-0120-0003' in txt, 'panel shows the point')

        print('== BOX NOW voucher for order %s (via the panel endpoint)' % o_bn)
        res = page.evaluate("""async (args) => { const r = await fetch(args.url, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams({order_id: args.id, boxnow: 1, service_type: 'next_day'}).toString()}); return r.json(); }""",
                            {'url': A('extension/shipping/courier_center/createVoucher'), 'id': o_bn})
        if res.get('success'):
            awbs.append(res['voucher'])
        check(res.get('success'), 'BOX NOW voucher: ' + json.dumps(res, ensure_ascii=False)[:160])

        print('== order list: columns, point select, print split')
        page.goto(A('sale/order'))
        check(page.locator('#cc-bulk-point option').count() == n_opts, 'bulk point select with %d points' % n_opts)
        heads = page.locator('#form-order thead .cc-col-head').all_inner_texts()
        check('Τύπος' in ' '.join(heads), 'Τύπος column: ' + ', '.join(heads))
        tr_bn = page.locator('#form-order tbody tr', has=page.locator('input[name="selected[]"][value="%s"]' % o_bn))
        check('BOX NOW' in tr_bn.inner_text(), 'BOX NOW badge on the BOX NOW order')
        for oid in (o_norm, o_bn):
            page.check('input[name="selected[]"][value="%s"]' % oid)
        page.evaluate("() => { window.__opened = []; window.open = function (u) { window.__opened.push(String(u)); return null; }; }")
        page.click('#cc-bulk-bar button:has-text("Εκτύπωση Vouchers")')
        note = page.locator('#cc-print-note')
        check(note.is_visible() and 'BOX NOW (1)' in note.inner_text(), 'note offers the BOX NOW PDF')
        note.locator('button').click()
        opened = page.evaluate("() => window.__opened") or []
        print('    url now:', page.url[-40:])
        print('    opened:', [u[-50:] for u in opened])
        check(len(opened) == 2 and 'bulkPrint&' in opened[0] and o_norm in opened[0] and 'bulkPrintBoxnow' in opened[1] and o_bn in opened[1], 'print: normal PDF first, BOX NOW PDF from the note')
        pdf = page.evaluate("async (u) => { const r = await fetch(u, {credentials: 'same-origin'}); const b = await r.text(); return b.slice(0, 4); }", opened[1] if len(opened) > 1 else A('x'))
        check(pdf == '%PDF', 'the BOX NOW PDF downloads')
        page.uncheck('input[name="selected[]"][value="%s"]' % o_bn)
        n_dialogs = len(dialogs)
        page.click('#cc-bulk-bar button:has-text("Εκτύπωση BOX NOW")')
        check(len(dialogs) > n_dialogs and 'BOX NOW voucher' in dialogs[-1], 'BOX NOW-only print with no BOX NOW order selected → alert')
        page.check('input[name="selected[]"][value="%s"]' % o_bn)
        page.evaluate("() => { window.__opened = []; }")
        page.click('#cc-bulk-bar button:has-text("Εκτύπωση BOX NOW")')
        opened_bn = page.evaluate("() => window.__opened") or []
        check(len(opened_bn) == 1 and 'bulkPrintBoxnow' in opened_bn[0] and o_bn in opened_bn[0] and o_norm not in opened_bn[0], 'BOX NOW-only print opens just the BOX NOW PDF')

        print('== customer return form JS')
        for k, v in (('enabled', '1'), ('require_approval', '1'), ('method_dropoff', '1'), ('method_boxnow', '1'), ('method_pickup', '1')):
            sql("DELETE FROM oc_setting WHERE `key`='shipping_courier_center_returns_%s'" % k)
            sql("INSERT INTO oc_setting SET store_id=0, code='shipping_courier_center', `key`='shipping_courier_center_returns_%s', value='%s', serialized=0" % (k, v))
        sql("DELETE FROM oc_cc_return_requests WHERE order_id=" + o_norm)
        email = 'ui-returns@example.com'
        sql("DELETE FROM oc_customer WHERE email='%s'" % email)
        sql("INSERT INTO oc_customer SET customer_group_id=1, store_id=0, language_id=1, firstname='UI', lastname='Test', email='%s', telephone='6912345678', fax='', password=MD5('ret123'), salt='', newsletter=0, address_id=0, custom_field='', ip='', status=1, safe=0, token='', code='', date_added=NOW()" % email)
        cid = sql("SELECT customer_id FROM oc_customer WHERE email='%s'" % email)[0][0]
        done = json.loads(sql("SELECT value FROM oc_setting WHERE `key`='config_complete_status'")[0][0])[0]
        sql("UPDATE oc_order SET customer_id=%s, order_status_id=%s WHERE order_id=%s" % (cid, done, o_norm))
        sql("INSERT INTO oc_order_history SET order_id=%s, order_status_id=%s, notify=0, comment='ui', date_added=NOW()" % (o_norm, done))
        cpage = br.new_context().new_page()
        cdialogs = []
        cpage.on('dialog', lambda d: (cdialogs.append(d.message), d.accept()))
        cpage.goto(BASE + 'index.php?route=account/login')
        cpage.fill('#input-email', email)
        cpage.fill('#input-password', 'ret123')
        cpage.click('#content input[type=submit], #content button[type=submit]')
        cpage.wait_for_load_state('load')
        print('    after login:', cpage.url)
        cpage.goto(BASE + 'index.php?route=account/order/info&order_id=' + o_norm)
        print('    order page:', cpage.url, cpage.locator('#cc-returns').count())
        check(cpage.locator('#cc-returns a:has-text("Αίτημα επιστροφής")').count() == 1, 'order page shows the button')
        cpage.goto(BASE + 'index.php?route=account/order')
        check(cpage.locator('a.cc-return-btn').count() >= 1, 'customer orders list shows «Αίτημα Επιστροφής»')
        cpage.goto(BASE + 'index.php?route=account/order/info&order_id=' + o_norm)
        cpage.click('#cc-returns a:has-text("Αίτημα επιστροφής")')
        cpage.wait_for_load_state('load')
        check(not cpage.locator('#cc-sizes').is_visible(), 'Drop Off by default: no locker sizes')

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
        cpage.check('input[name="method"][value="BOXNOW"]')
        check(cpage.locator('#cc-sizes').is_visible() and not cpage.locator('#cc-landline-row').is_visible(), 'BOX NOW shows sizes, hides the landline')
        cpage.click('#cc-submit')
        check(cdialogs and 'τουλάχιστον ένα τεμάχιο' in cdialogs[-1], 'no quantity → alert: ' + (cdialogs[-1] if cdialogs else '-'))
        cpage.locator('input[name^="qty["]').first.fill('1')
        cpage.click('#cc-submit')
        check('μέγεθος' in cdialogs[-1], 'BOX NOW without size → alert')
        cpage.check('input[name="locker_size"][value="medium"]')
        cpage.click('#cc-submit')
        cpage.wait_for_load_state('load')
        check('καταχωρίστηκε' in cpage.inner_text('body'), 'submitted → flash on the order page')
        req = sql("SELECT id, status, locker_size FROM oc_cc_return_requests WHERE order_id=%s ORDER BY id DESC LIMIT 1" % o_norm)
        check(req and req[0][1] == 'pending_approval' and req[0][2] == 'medium', 'request stored: ' + str(req))


        print('== admin «Νέα επιστροφή» JS + notice (nothing submitted)')
        page.goto(A('common/dashboard'))
        check(page.locator('#cc-returns-notice').count() == 1, 'dashboard notice while a request waits')
        page.goto(A('extension/shipping/courier_center/returns/add', '&order_id=' + o_norm))
        page.wait_for_load_state('load')
        check(page.locator('#cc-return-form').count() == 1 and page.locator('input[name="method"]').count() == 3, '«Νέα επιστροφή» form with the 3 methods')
        page.check('input[name="method"][value="COURIER_CENTER_PICKUP"]')
        check(page.locator('#cc-pickup').is_visible(), 'admin form: Pickup shows its fields')
        page.check('input[name="method"][value="BOXNOW"]')
        check(page.locator('#cc-sizes').is_visible() and not page.locator('#cc-pickup').is_visible(), 'admin form: BOX NOW shows the sizes')
        page.click('#cc-submit')
        check(dialogs and 'τουλάχιστον ένα τεμάχιο' in dialogs[-1], 'admin form: no quantity → alert')

        print('== admin returns page: reject button')
        page.goto(A('extension/shipping/courier_center/returns'))
        page.click('tr[data-id="%s"] [data-cc-ret="reject"]' % req[0][0])
        page.wait_for_load_state('load')
        page.wait_for_timeout(1000)
        st = sql("SELECT status FROM oc_cc_return_requests WHERE id=" + req[0][0])
        check(st and st[0][0] == 'rejected', 'rejected from the list (confirm accepted)')
        sql("DELETE FROM oc_cc_return_requests WHERE order_id=" + o_norm)
        sql("DELETE FROM oc_customer WHERE customer_id=" + cid)
        sql("UPDATE oc_setting SET value='0' WHERE `key`='shipping_courier_center_returns_enabled'")
        check(not errors, 'no JavaScript errors: ' + '; '.join(errors[:3]))
        br.close()


try:
    main()
finally:
    awbs += [r[0] for r in sql("SELECT return_awb FROM oc_cc_return_requests WHERE return_awb <> '' AND created_at > NOW() - INTERVAL 15 MINUTE AND status = 'shipment_created'")]
    if awbs:
        out = subprocess.run([PHP, 'tools/ui/void.php'] + awbs, capture_output=True, text=True, encoding='utf-8', env=dict(__import__('os').environ, CC_UI_DB=DB))
        print(out.stdout.strip())
    print('\n[UI] %d ok, %d failed' % (ok, len(fail)))
    for f in fail:
        print('  - ' + f)
