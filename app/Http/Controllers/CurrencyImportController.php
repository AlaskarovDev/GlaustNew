<?php

namespace App\Http\Controllers;

use App\Models\CurrencyRate;
use App\Rules\SpreadsheetFile;
use App\Services\Cbar\CbarClient;
use App\Services\Cbar\CurrencyRates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CBAR kursları importu — for when cbar.az cannot be reached: a bulletin downloaded from cbar.az (XML, several at once),
 * an Excel list (the template: Tarix · Valyuta · Məzənnə · Nominal) or a rate typed in. The rates are platform-wide;
 * CBAR's own is never overwritten, and CBAR's bulletin replaces these the next time it answers for that day.
 */
class CurrencyImportController extends Controller
{
    public function __construct(private CurrencyRates $rates) {}

    public function index(): View
    {
        $this->authorize('currency.import');

        return view('currency.import', [
            'recent' => CurrencyRate::with('creator:id,name')->whereIn('source', CurrencyRates::MANUAL_SOURCES)->orderByDesc('rate_date')->orderBy('currency_code')->limit(200)->get(),
        ]);
    }

    /** Bulletins downloaded from cbar.az (https://www.cbar.az/currencies/DD.MM.YYYY.xml), one file per day. */
    public function xml(Request $request, CbarClient $client): RedirectResponse
    {
        $this->authorize('currency.import');
        $request->validate(['files' => ['required', 'array', 'max:60'], 'files.*' => ['file', 'max:512']], [], ['files' => __('XML faylları')]);
        $done = $bad = [];
        foreach ($request->file('files') as $file) {
            $xml = (string) file_get_contents($file->getRealPath());
            $day = $client->bulletinDate($xml);
            $list = $day ? $client->parse($xml) : [];
            if (! $list) {
                $bad[] = $file->getClientOriginalName();

                continue;
            }
            foreach ($list as $code => $r) {
                $this->rates->putManual($code, $day, $r['rate'], $r['nominal'], $r['name'], 'CBAR fayl', $request->user()->id);
            }
            $done[] = azdate($day).' ('.count($list).')';
        }

        return back()->with($done ? 'success' : 'error', ($done ? __('Yükləndi: :v1.', ['v1' => implode(', ', $done)]) : '')
            .($bad ? ' '.__('Oxunmadı (CBAR bülleteni deyil): :v1.', ['v1' => implode(', ', $bad)]) : ''));
    }

    /** The Excel list, in the template's layout. */
    public function excel(Request $request): RedirectResponse
    {
        $this->authorize('currency.import');
        $request->validate(['file' => ['required', 'file', 'max:5120', new SpreadsheetFile(['xlsx', 'xls', 'csv'])]]);
        try {
            $rows = IOFactory::load($request->file('file')->getRealPath())->getActiveSheet()->toArray(null, true, false, false);
        } catch (\Throwable) {
            return back()->withErrors(['file' => __('Fayl oxunmadı. Şablondakı kimi Excel faylı yükləyin.')]);
        }
        $count = ['added' => 0, 'updated' => 0, 'kept' => 0];
        $errors = [];
        foreach (array_slice($rows, 1) as $i => $row) {
            if (! array_filter($row, fn ($v) => $v !== null && $v !== '')) {
                continue;
            }
            [$day, $cur, $rate, $nominal] = [self::date($row[0] ?? null), strtoupper(trim((string) ($row[1] ?? ''))), self::num($row[2] ?? null), self::num($row[3] ?? null) ?: 1.0];
            $line = $i + 2;
            if (! $day || ! preg_match('/^[A-Z]{3}$/', $cur) || $cur === 'AZN' || ! $rate || $rate <= 0 || $nominal <= 0) {
                $errors[] = __(':v1-ci sətir: tarix, valyuta (məs. EUR) və məzənnə düzgün deyil.', ['v1' => $line]);

                continue;
            }
            if ($day > $this->rates->today()->format('Y-m-d')) {
                $errors[] = __(':v1-ci sətir: gələcək tarix olmaz.', ['v1' => $line]);

                continue;
            }
            $count[$this->rates->putManual($cur, $day, $rate / $nominal, $nominal, null, 'Excel', $request->user()->id)]++;
        }

        return back()->with($errors && ! $count['added'] && ! $count['updated'] ? 'error' : 'success',
            __('Əlavə olundu: :v1, yeniləndi: :v2, CBAR-ın öz kursu olduğu üçün toxunulmadı: :v3.', ['v1' => $count['added'], 'v2' => $count['updated'], 'v3' => $count['kept']])
            .($errors ? ' '.implode(' ', array_slice($errors, 0, 5)).(count($errors) > 5 ? ' …' : '') : ''));
    }

    /** One rate typed in. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('currency.import');
        $request->merge(['rate' => parse_number($request->input('rate')), 'nominal' => parse_number($request->input('nominal')) ?: 1]);
        $d = $request->validate([
            'rate_date' => ['required', 'date', 'before_or_equal:today'],
            'currency_code' => ['required', 'regex:/^[A-Za-z]{3}$/', 'not_in:AZN,azn'],
            'rate' => ['required', 'numeric', 'gt:0'],
            'nominal' => ['required', 'numeric', 'gt:0'],
        ], [], ['rate_date' => __('Tarix'), 'currency_code' => __('Valyuta'), 'rate' => __('Məzənnə')]);
        $res = $this->rates->putManual($d['currency_code'], $d['rate_date'], (float) $d['rate'] / (float) $d['nominal'], (float) $d['nominal'], null, 'Əl ilə', $request->user()->id);

        return back()->with($res === 'kept' ? 'error' : 'success', $res === 'kept'
            ? __('Bu gün üçün CBAR-ın öz kursu var — dəyişdirilmədi.')
            : __(':v1 :v2 üçün məzənnə yazıldı.', ['v1' => strtoupper($d['currency_code']), 'v2' => azdate($d['rate_date'])]));
    }

    /** A hand-made rate taken back (CBAR's own cannot be). */
    public function destroy(CurrencyRate $rate): RedirectResponse
    {
        $this->authorize('currency.import');
        abort_unless(in_array($rate->source, CurrencyRates::MANUAL_SOURCES, true), 403);
        $rate->delete();
        \Illuminate\Support\Facades\Cache::forget('cbar_rates_'.$rate->rate_date->format('Ymd'));

        return back()->with('success', __('Məzənnə silindi.'));
    }

    public function template(): StreamedResponse
    {
        $this->authorize('currency.import');
        $book = new Spreadsheet;
        $s = $book->getActiveSheet()->setTitle('Məzənnələr');
        $s->fromArray([
            [__('Tarix'), __('Valyuta'), __('Məzənnə (nominal üçün AZN)'), __('Nominal')],
            ['11.03.2025', 'EUR', 1.8435, 1],
            ['11.03.2025', 'RUB', 1.9551, 100],
            ['11.03.2025', 'USD', 1.7, 1],
        ]);
        $s->getStyle('A1:D1')->getFont()->setBold(true);
        $s->getStyle('A1:D1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('92D050');
        foreach (['A' => 14, 'B' => 10, 'C' => 28, 'D' => 10] as $c => $w) {
            $s->getColumnDimension($c)->setWidth($w);
        }
        $s->setCellValue('F1', __('Məzənnə — CBAR-ın yazdığı kimi, nominal üçün: məs. 100 RUB = 1,9551 AZN. Tarix: GG.AA.İİİİ.'));

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), 'cbar-mezenneler-sablon.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private static function date($v): ?string
    {
        if (is_numeric($v) && $v > 20000) {
            return ExcelDate::excelToDateTimeObject((float) $v)->format('Y-m-d');
        }
        foreach (['d.m.Y', 'Y-m-d', 'd/m/Y', 'd-m-Y'] as $f) {
            $d = \DateTime::createFromFormat('!'.$f, trim((string) $v));
            if ($d && $d->format($f) === trim((string) $v)) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }

    private static function num($v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $v = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim((string) $v));

        return is_numeric($v) ? (float) $v : null;
    }
}
