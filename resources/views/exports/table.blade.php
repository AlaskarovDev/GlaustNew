@extends('exports.layout')
@section('content')
    <h1>{{ $title }}</h1>
    <div class="sub">
        {{ count($rows) }} qeyd
        @if($filters) · {{ implode(' · ', $filters) }} @endif
        · Məbləğlər AZN-dədir, əgər sütunda başqa valyuta göstərilməyibsə.
    </div>
    <table class="data">
        <thead>
        <tr>
            @foreach($columns as $col)
                <th @class(['num' => in_array($col->type, ['money', 'number', 'rate'])])>{{ $col->label }}</th>
            @endforeach
        </tr>
        </thead>
        <tbody>
        @forelse($rows as $row)
            <tr>
                @foreach($row as $i => $cell)
                    <td @class(['num' => in_array($columns[$i]->type, ['money', 'number', 'rate'])])>{{ $cell }}</td>
                @endforeach
            </tr>
        @empty
            <tr><td colspan="{{ count($columns) }}" style="text-align:center;color:#98a2b3;padding:18px">Məlumat yoxdur</td></tr>
        @endforelse
        </tbody>
        @if($totals)
            <tfoot>
            <tr>
                @foreach($columns as $i => $col)
                    <td @class(['num' => isset($totals[$i])])>{{ $i === 0 ? 'Cəmi' : (isset($totals[$i]) ? num($totals[$i]) : '') }}</td>
                @endforeach
            </tr>
            </tfoot>
        @endif
    </table>
    @if($truncated)
        <p class="note">PDF-də ilk {{ $maxRows }} qeyd göstərilib. Tam siyahı üçün Excel exportundan istifadə edin.</p>
    @endif
@endsection
