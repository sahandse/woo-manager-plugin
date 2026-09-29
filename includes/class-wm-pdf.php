<?php
defined('ABSPATH') || exit;
final class WM_PDF {
    public static function from_html(string $html, string $filename, string $paper = 'A4') {
        if (!class_exists('Dompdf\\Dompdf')) return new WP_Error('pdf_engine_missing','موتور PDF نصب نشده است؛ بسته Release افزونه را نصب کنید.',['status'=>503]);
        $dompdf=new Dompdf\Dompdf(['isRemoteEnabled'=>false,'isHtml5ParserEnabled'=>true]);
        $dompdf->loadHtml('<!doctype html><html dir="rtl" lang="fa"><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;direction:rtl} @page{margin:12mm}</style><body>'.$html.'</body></html>','UTF-8');
        $dompdf->setPaper($paper,'portrait');$dompdf->render();
        return ['filename'=>sanitize_file_name($filename),'mime'=>'application/pdf','content_base64'=>base64_encode($dompdf->output())];
    }
}
