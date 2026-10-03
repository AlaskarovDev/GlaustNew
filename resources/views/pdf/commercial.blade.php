{{-- Commercial Invoice (the INVOICE sheet): the proforma layout with its own title and labels. --}}
@include('pdf.proforma', ['docTitle' => 'COMMERCIAL INVOICE', 'prefix' => 'Inv.'])
