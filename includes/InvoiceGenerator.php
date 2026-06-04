<?php
declare(strict_types=1);

class InvoiceGenerator
{
    private array  $customer;
    private array  $entries;
    private string $invoiceNumber;
    private float  $rate;
    private float  $amountNet;
    private float  $taxAmount;
    private float  $amountGross;
    private int    $taxRate;
    private int    $paymentDays;
    private array  $cfg;
    private string $invoiceDir;
    private int    $totalMinutes;
    private bool   $isTextMode;

    private static function qhRound(int $min): float
    {
        return round($min / 15) * 0.25;
    }

    private static function entryDate(array $e): string
    {
        return $e['date'] ?? (isset($e['start_datetime'])
            ? date('Y-m-d', strtotime($e['start_datetime']))
            : date('Y-m-d'));
    }

    /**
     * @param array      $customer     Kunden-/Rechnungskontext (inkl. invoice_mode, hourly_rate)
     * @param array      $entries      Rechnungsposten
     * @param string     $invoiceNumber
     * @param array|null $masterTotals Stammdaten-Beträge der Rechnung
     *                                 (total_minutes, amount_net, amount_gross, tax_rate).
     *                                 Im Text-Modus sind diese maßgeblich und werden
     *                                 NICHT aus den Posten neu berechnet.
     */
    public function __construct(array $customer, array $entries, string $invoiceNumber, ?array $masterTotals = null)
    {
        $this->customer      = $customer;
        $this->entries       = $entries;
        $this->invoiceNumber = $invoiceNumber;
        $this->invoiceDir    = dirname(__DIR__) . '/invoices';

        $this->cfg = [
            'company'        => cfg('invoice_company',        'Firma'),
            'street'         => cfg('invoice_street',         ''),
            'zip'            => cfg('invoice_zip',            ''),
            'city'           => cfg('invoice_city',           ''),
            'email'          => cfg('invoice_email',          ''),
            'phone'          => cfg('invoice_phone',          ''),
            'tax_id'         => cfg('invoice_tax_id',         ''),
            'tax_number'     => cfg('invoice_tax_number',     ''),
            'iban'           => cfg('invoice_iban',           ''),
            'bic'            => cfg('invoice_bic',            ''),
            'bank'           => cfg('invoice_bank',           ''),
            'account_holder' => cfg('invoice_account_holder', ''),
        ];

        // Steuersatz: bevorzugt aus den Stammdaten der Rechnung, sonst Konfiguration
        $this->taxRate     = isset($masterTotals['tax_rate']) && $masterTotals['tax_rate'] !== null
            ? (int)$masterTotals['tax_rate']
            : (int)cfg('invoice_tax_rate', '19');
        $this->paymentDays = (int)cfg('invoice_payment_days', '14');
        $this->rate        = (float)($customer['hourly_rate'] ?: cfg('invoice_hourly_rate', '85.00'));

        $this->isTextMode  = ($customer['invoice_mode'] ?? 'entries') === 'text';

        if ($this->isTextMode && $masterTotals !== null) {
            // Text-Modus: Rechnungs-Stammdaten sind Master
            $this->totalMinutes = (int)($masterTotals['total_minutes'] ?? 0);
            $this->amountNet    = round((float)($masterTotals['amount_net'] ?? 0), 2);
            if (isset($masterTotals['amount_gross']) && $masterTotals['amount_gross'] !== null) {
                $this->amountGross = round((float)$masterTotals['amount_gross'], 2);
                $this->taxAmount   = round($this->amountGross - $this->amountNet, 2);
            } else {
                $this->taxAmount   = round($this->amountNet * $this->taxRate / 100, 2);
                $this->amountGross = round($this->amountNet + $this->taxAmount, 2);
            }
        } else {
            // Einzelposten: Beträge aus den Posten berechnen
            $this->amountNet    = 0.0;
            $minutes            = 0;
            foreach ($entries as $e) {
                $this->amountNet += self::qhRound((int)$e['duration_minutes']) * $this->rate;
                $minutes         += (int)$e['duration_minutes'];
            }
            $this->amountNet    = round($this->amountNet, 2);
            $this->totalMinutes = $minutes;
            $this->taxAmount    = round($this->amountNet * $this->taxRate / 100, 2);
            $this->amountGross  = round($this->amountNet + $this->taxAmount, 2);
        }
    }

    // -----------------------------------------------------------------------
    // PDF (mPDF) + ZUGFeRD XML attachment (horstoeko/zugferd)
    // -----------------------------------------------------------------------
    public function generatePdf(): string
    {
        $this->requireAutoloader();

        $year     = date('Y');
        $filename = $this->makeFilename() . '.pdf';
        $pdfDir   = $this->invoiceDir . '/pdf/' . $year;
        $pdfPath  = $pdfDir . '/' . $filename;

        if (!is_dir($pdfDir)) {
            @mkdir($pdfDir, 0777, true);
            @chmod($pdfDir, 0777);
        }

        $tempDir = dirname(__DIR__) . '/tmp/mpdf';
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $mpdf = new \Mpdf\Mpdf([
            'mode'          => 'UTF-8',
            'format'        => 'A4',
            'margin_top'    => 15,
            'margin_bottom' => 32,
            'margin_left'   => 20,
            'margin_right'  => 20,
            'tempDir'       => $tempDir,
        ]);
        $mpdf->SetHTMLFooter($this->buildFooterHtml());
        $mpdf->WriteHTML($this->buildHtml());
        $mpdf->Output($pdfPath, \Mpdf\Output\Destination::FILE);

        $this->attachZugferd($pdfPath);

        return $year . '/' . $filename;
    }

    // -----------------------------------------------------------------------
    // ZUGFeRD / XRechnung XML attachment
    // -----------------------------------------------------------------------
    private function attachZugferd(string $pdfPath): void
    {
        try {
            $doc = \horstoeko\zugferd\ZugferdDocumentBuilder::createNew(
                \horstoeko\zugferd\ZugferdProfiles::PROFILE_EN16931
            );

            $c = $this->cfg;
            $dueDate = new \DateTime('+' . $this->paymentDays . ' days');

            $doc->setDocumentInformation($this->invoiceNumber, "380", new \DateTime(), "EUR");

            $doc->setDocumentSeller($c['company']);
            $doc->setDocumentSellerAddress($c['street'], '', '', $c['zip'], $c['city'], "DE");
            if ($c['tax_id']) {
                $doc->addDocumentSellerVATRegistrationNumber($c['tax_id']);
            }
            if ($c['tax_number']) {
                $doc->addDocumentSellerTaxNumber($c['tax_number']);
            }
            if ($c['email']) {
                $doc->setDocumentSellerContact(null, null, $c['phone'] ?: null, null, $c['email']);
            }

            $buyerName = $this->customer['billing_name'] ?: $this->customer['name'];
            $doc->setDocumentBuyer($buyerName);
            if (!empty($this->customer['billing_street'])) {
                $doc->setDocumentBuyerAddress(
                    $this->customer['billing_street'],
                    '', '',
                    $this->customer['billing_zip']  ?? '',
                    $this->customer['billing_city'] ?? '',
                    "DE"
                );
            }
            if (!empty($this->customer['billing_tax_id'])) {
                $doc->addDocumentBuyerVATRegistrationNumber($this->customer['billing_tax_id']);
            }

            $doc->addDocumentPaymentTerm(
                sprintf('Zahlbar innerhalb von %d Tagen', $this->paymentDays),
                $dueDate
            );

            $doc->addDocumentTax("S", "VAT", $this->amountNet, $this->taxAmount, (float)$this->taxRate);

            if ($this->isTextMode) {
                $invoiceText = trim((string)($this->customer['invoice_text'] ?? ''));
                $projects    = json_decode($this->customer['projects'] ?? '[]', true);
                $firstProj   = (is_array($projects) && !empty($projects)) ? trim($projects[0]['name'] ?? '') : '';
                if ($invoiceText !== '' && $firstProj !== '') {
                    $invoiceText = str_replace('{project}', $firstProj, $invoiceText);
                }

                // Eine Position als Pauschale: Menge 1 × Netto = Master-Nettobetrag.
                // So bleibt die XML konsistent, auch wenn Stunden und Betrag in den
                // Stammdaten unabhängig voneinander gesetzt wurden.
                $doc->addNewPosition("1");
                $doc->setDocumentPositionProductDetails($invoiceText !== '' ? $invoiceText : 'Leistung');
                $doc->setDocumentPositionNetPrice($this->amountNet);
                $doc->setDocumentPositionQuantity(1.0, "C62");
                $doc->addDocumentPositionTax("S", "VAT", (float)$this->taxRate);
                $doc->setDocumentPositionLineSummation($this->amountNet);
            } else {
                $pos = 1;
                foreach ($this->entries as $e) {
                    $hours     = self::qhRound((int)$e['duration_minutes']);
                    $lineTotal = round($hours * $this->rate, 2);
                    $desc      = $e['activity'] . ($e['comment'] ? ': ' . $e['comment'] : '');

                    $doc->addNewPosition((string)$pos);
                    $doc->setDocumentPositionProductDetails($desc);
                    $doc->setDocumentPositionNetPrice($this->rate);
                    $doc->setDocumentPositionQuantity($hours, "HUR");
                    $doc->addDocumentPositionTax("S", "VAT", (float)$this->taxRate);
                    $doc->setDocumentPositionLineSummation($lineTotal);
                    $pos++;
                }
            }

            $doc->setDocumentSummation(
                $this->amountGross,
                $this->amountGross,
                $this->amountNet,
                0.0, 0.0,
                $this->amountNet,
                $this->taxAmount
            );

            $pdfBuilder = \horstoeko\zugferd\ZugferdDocumentPdfBuilder::fromPdfFile($doc, $pdfPath);
            $pdfBuilder->generateDocument();
            $pdfBuilder->saveDocument($pdfPath);

        } catch (\Throwable $e) {
            error_log('ZUGFeRD: ' . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    // HTML-Template für mPDF
    // -----------------------------------------------------------------------
    private function buildHtml(): string
    {
        $c           = $this->cfg;
        $customer    = $this->customer;
        $rate        = $this->rate;
        $taxRate     = $this->taxRate;
        $amountNet   = $this->amountNet;
        $taxAmount   = $this->taxAmount;
        $amountGross = $this->amountGross;
        $todayStr    = date('d.m.Y');

        $dates       = array_map(fn($e) => self::entryDate($e), $this->entries);
        sort($dates);
        $periodStart = $dates ? date('d.m.Y', strtotime($dates[0])) : $todayStr;
        $periodEnd   = $dates ? date('d.m.Y', strtotime(end($dates))) : $todayStr;

        $invoiceMode = ($customer['invoice_mode'] ?? 'entries') === 'text' ? 'text' : 'entries';
        $invoiceText = trim((string)($customer['invoice_text'] ?? ''));
        $projects    = json_decode($customer['projects'] ?? '[]', true);
        $firstProj   = (is_array($projects) && !empty($projects)) ? trim($projects[0]['name'] ?? '') : '';
        if ($invoiceText !== '' && $firstProj !== '') {
            $invoiceText = str_replace('{project}', $firstProj, $invoiceText);
        }

        $esc = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $eur = fn(float $v): string  => number_format($v, 2, ',', '.') . ' €';
        $hrs = fn(int $m): string    => number_format(self::qhRound($m), 2, ',', '.');

        // Total hours: im Text-Modus aus den Stammdaten, sonst pro Posten gerundet
        if ($this->isTextMode) {
            $totalH = $this->totalMinutes / 60;
        } else {
            $totalH = 0.0;
            foreach ($this->entries as $e) { $totalH += self::qhRound((int)$e['duration_minutes']); }
        }

        ob_start(); ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
body            { font-family: Arial, sans-serif; font-size: 12px; color: #222; margin: 0; }
p               { margin: 2px 0; }
table           { border-collapse: collapse; }
.hdr            { width: 100%; margin-bottom: 32px; }
.hdr td         { border: none; padding: 0; vertical-align: top; }
.sender         { text-align: right; font-size: 11px; color: #444; line-height: 1.6; }
.sender .scomp  { font-size: 14px; font-weight: 700; color: #111; }
.recipient      { padding: 0 0 24px; font-size: 11px; }
.recipient p    { margin: 2px 0; }
.recipient .recname { font-weight: 700; font-size: 12px; }
.numrow         { width: 100%; margin-bottom: 14px; font-size: 12px; font-weight: 600; color: #111; }
.numrow td      { border: none; padding: 0; }
.numrow .right  { text-align: right; }
.subject        { margin-bottom: 16px; font-size: 12px; }
.subject strong { font-weight: 600; }
.itable         { width: 100%; margin-bottom: 22px; font-size: 11px; }
.itable th      { background: #f0f4f8; padding: 7px 9px; text-align: left;
                  border-bottom: 2px solid #cfd7e0; font-weight: 600; color: #333; }
.itable td      { padding: 6px 9px; border-bottom: 1px solid #eee; vertical-align: top; }
.itable .right  { text-align: right; }
.comment        { color: #777; font-size: 10px; }
</style>
</head>
<body>

<table class="hdr">
<tr>
  <td></td>
  <td class="sender">
    <span class="scomp"><?= $esc($c['company']) ?></span><br>
    <?= $esc($c['street']) ?><br>
    <?= $esc($c['zip']) ?> <?= $esc($c['city']) ?><br>
    <?php if ($c['email']): ?><?= $esc($c['email']) ?><br><?php endif; ?>
    <?php if ($c['phone']): ?><?= $esc($c['phone']) ?><br><?php endif; ?>
    <?php if ($c['tax_id']): ?>USt-IdNr.: <?= $esc($c['tax_id']) ?><?php endif; ?>
  </td>
</tr>
</table>

<div class="recipient">
  <p class="recname"><?= $esc($customer['billing_name'] ?: $customer['name']) ?></p>
  <?php
  $contactName = trim(($customer['contact_first_name'] ?? '') . ' ' . ($customer['contact_last_name'] ?? ''));
  if ($customer['contact_on_invoice'] && $contactName): ?>
  <p>z.&nbsp;Hd. <?= $esc($contactName) ?></p>
  <?php else: ?>
  <p>&nbsp;</p>
  <?php endif; ?>
  <?php if ($customer['billing_street']): ?>
  <p><?= $esc($customer['billing_street']) ?></p>
  <?php endif; ?>
  <?php $plz = trim(($customer['billing_zip'] ?? '') . ' ' . ($customer['billing_city'] ?? '')); if ($plz): ?>
  <p><?= $esc($plz) ?></p>
  <?php endif; ?>
  <?php if ($customer['billing_tax_id']): ?>
  <p style="margin-top:6px">USt-IdNr.: <?= $esc($customer['billing_tax_id']) ?></p>
  <?php endif; ?>
</div>

<br><br><br>
<table class="numrow">
<tr>
  <td>Rechnung Nr. <?= $esc($this->invoiceNumber) ?></td>
  <td class="right"><?= $todayStr ?></td>
</tr>
</table>

<div class="subject">
  Zeitraum: <?= $esc($periodStart) ?><?= $periodStart !== $periodEnd ? ' – ' . $esc($periodEnd) : '' ?>
</div>

<?php if ($invoiceMode === 'text'): ?>
<table class="itable">
<thead>
  <tr>
    <th>Beschreibung</th>
    <th class="right">Std.</th>
    <th class="right">Betrag</th>
  </tr>
</thead>
<tbody>
  <tr>
    <td><?= $esc($invoiceText) ?></td>
    <td class="right"><?= number_format($totalH, 2, ',', '.') ?></td>
    <td class="right"><?= $eur($amountNet) ?></td>
  </tr>
</tbody>
</table>
<?php else: ?>
<table class="itable">
<thead>
  <tr>
    <th>Datum</th>
    <th>Tätigkeit &amp; Kommentar</th>
    <th class="right">Std.</th>
    <th class="right">Betrag</th>
  </tr>
</thead>
<tbody>
<?php foreach ($this->entries as $e):
    $hours  = self::qhRound((int)$e['duration_minutes']);
    $amount = round($hours * $rate, 2);
    $rowDate = date('d.m.Y', strtotime(self::entryDate($e)));
?>
  <tr>
    <td><?= $esc($rowDate) ?></td>
    <td>
      <?= $esc($e['activity']) ?>
      <?php if ($e['comment']): ?>
        <br><span class="comment"><?= $esc($e['comment']) ?></span>
      <?php endif; ?>
    </td>
    <td class="right"><?= $hrs((int)$e['duration_minutes']) ?></td>
    <td class="right"><?= $eur($amount) ?></td>
  </tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>

<table width="100%" style="border-collapse:collapse; margin-bottom:26px;">
<tr>
  <td style="border:none; padding:0; width:55%;"></td>
  <td style="border:none; padding:0; width:45%;">
    <table width="100%" style="border-collapse:collapse;">
      <tr>
        <td style="padding:4px 10px; font-size:12px; color:#333;">Nettobetrag</td>
        <td style="padding:4px 10px; font-size:12px; color:#333; text-align:right; font-weight:600;"><?= $eur($amountNet) ?></td>
      </tr>
      <tr>
        <td style="padding:4px 10px; font-size:12px; color:#333;">zzgl. <?= $taxRate ?>&nbsp;% MwSt.</td>
        <td style="padding:4px 10px; font-size:12px; color:#333; text-align:right; font-weight:600;"><?= $eur($taxAmount) ?></td>
      </tr>
      <tr>
        <td style="padding:8px 10px 4px; font-size:14px; font-weight:700; color:#111; border-top:2px solid #111;">Gesamtbetrag</td>
        <td style="padding:8px 10px 4px; font-size:14px; font-weight:700; color:#111; border-top:2px solid #111; text-align:right;"><?= $eur($amountGross) ?></td>
      </tr>
    </table>
  </td>
</tr>
</table>


</body>
</html>
<?php
        return (string)ob_get_clean();
    }

    // -----------------------------------------------------------------------
    // Footer HTML for SetHTMLFooter
    // -----------------------------------------------------------------------
    private function buildFooterHtml(): string
    {
        $c   = $this->cfg;
        $esc = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        ob_start(); ?>
<table width="100%" style="border-collapse:collapse; border-top:1px solid #ccc; padding-top:6px; font-size:10px; color:#555;">
<tr>
  <td style="border:none; padding:4px 16px 0 0; vertical-align:top; width:50%;">
    <strong style="font-weight:600; color:#333;">Bankverbindung</strong><br>
    <?php if ($c['account_holder']): ?>Kontoinhaber: <?= $esc($c['account_holder']) ?><br><?php endif; ?>
    Bank: <?= $esc($c['bank']) ?><br>
    IBAN: <?= $esc($c['iban']) ?><br>
    BIC: <?= $esc($c['bic']) ?>
  </td>
  <?php if ($c['tax_number']): ?>
  <td style="border:none; padding:4px 0 0 0; vertical-align:top; width:50%;">
    <strong style="font-weight:600; color:#333;">Steuernummer</strong><br>
    <?= $esc($c['tax_number']) ?>
  </td>
  <?php else: ?>
  <td style="border:none;"></td>
  <?php endif; ?>
</tr>
</table>
<?php
        return (string)ob_get_clean();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------
    private function makeFilename(): string
    {
        $number = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $this->invoiceNumber);
        $raw    = $this->customer['name'];
        $name   = preg_replace('/_+/', '_', preg_replace('/[^a-zA-Z0-9\-_]/', '_', $raw));
        $name   = trim($name, '_');
        return $number . '_' . $name . '_' . date('Ymd');
    }

    private function requireAutoloader(): void
    {
        $loader = dirname(__DIR__) . '/vendor/autoload.php';
        if (!file_exists($loader)) {
            throw new \RuntimeException(
                'Composer-Autoloader nicht gefunden. Bitte im Verzeichnis time_manager/ den Befehl "composer install" ausführen.'
            );
        }
        require_once $loader;
    }
}
