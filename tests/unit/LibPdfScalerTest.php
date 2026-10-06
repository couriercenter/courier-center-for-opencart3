<?php
require_once CC_LIB . '/CCPdfScaler.php';

use Opencart\Extension\Couriercenter\Library\CCPdfScaler;

/** A PDF with $pages pages of 100x150 mm, made with the bundled FPDF. */
function cc_make_label_pdf($pages) {
    if (!class_exists('FPDF', false)) {
        require_once CC_LIB . '/lib/fpdf/fpdf.php';
    }
    $pdf = new FPDF('P', 'mm', array(100, 150));
    $pdf->SetFont('Helvetica', '', 12);
    for ($i = 1; $i <= $pages; $i++) {
        $pdf->AddPage();
        $pdf->Cell(40, 10, 'Label ' . $i);
    }
    return $pdf->Output('S');
}

/** [page count, width mm, height mm] of a PDF (first page size). */
function cc_pdf_pages($bytes) {
    $fpdi = new \setasign\Fpdi\Fpdi();
    $count = $fpdi->setSourceFile(\setasign\Fpdi\PdfParser\StreamReader::createByString($bytes));
    $size = $fpdi->getTemplateSize($fpdi->importPage(1));
    return array($count, (int)round($size['width']), (int)round($size['height']));
}

function test_lib_pdf_arrange_4up_puts_four_labels_on_one_a4_page() {
    $out = CCPdfScaler::arrange_4up(cc_make_label_pdf(4));
    assert_same('%PDF', substr($out, 0, 4));
    assert_same(array(1, 210, 297), cc_pdf_pages($out));
}
function test_lib_pdf_arrange_4up_starts_a_new_page_after_four() {
    $pages = cc_pdf_pages(CCPdfScaler::arrange_4up(cc_make_label_pdf(5)));
    assert_same(2, $pages[0]);
}
function test_lib_pdf_scale_keeps_pages_and_size() {
    assert_same(array(2, 100, 150), cc_pdf_pages(CCPdfScaler::scale_pdf(cc_make_label_pdf(2))));
}
function test_lib_pdf_scale_does_not_use_the_temp_dir() {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cc_temp';
    if (is_dir($dir)) {
        @rmdir($dir);
    }
    if (is_dir($dir)) {
        return; // not empty: another program uses it, cannot check here
    }
    CCPdfScaler::scale_pdf(cc_make_label_pdf(1));
    assert_false(is_dir($dir), 'scale_pdf must read the PDF from memory (open_basedir-safe, spec 5.3)');
}
function test_lib_pdf_reuses_an_fpdf_loaded_by_another_extension() {
    $tmp = sys_get_temp_dir() . '/cc_other_ext_' . getmypid();
    @mkdir($tmp);
    copy(CC_LIB . '/lib/fpdf/fpdf.php', $tmp . '/fpdf.php');
    $script = $tmp . '/run.php';
    file_put_contents($script, '<?php error_reporting(E_ALL); set_error_handler(function ($s, $m) { throw new ErrorException($m); });'
        . ' require ' . var_export($tmp . '/fpdf.php', true) . ';'
        . ' require ' . var_export(CC_LIB . '/CCPdfScaler.php', true) . ';'
        . ' $p = new FPDF("P", "mm", array(100, 150)); $p->AddPage(); $bytes = $p->Output("S");'
        . ' echo substr(\Opencart\Extension\Couriercenter\Library\CCPdfScaler::arrange_4up($bytes), 0, 4);');
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
    @unlink($script);
    @unlink($tmp . '/fpdf.php');
    @rmdir($tmp);
    assert_same('%PDF', trim((string)$out), 'another extension\'s FPDF must not cause "Cannot declare class FPDF"');
}
