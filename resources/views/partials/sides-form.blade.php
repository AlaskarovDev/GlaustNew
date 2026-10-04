{{-- Buyer and seller sides with their contracts. Fields are named alike on projects and deals.
     $holder: Project|Deal (relations counterparty, saleContract, supplier, purchaseContract); $files: show the PDF upload. --}}
            <div class="grid xl:grid-cols-2 gap-6">
                @foreach([
                    ['party' => 'counterparty_id', 'contract' => 'sale_contract_id', 'kind' => 'sale', 'role' => 'customer', 'icon' => 'arrow-up-right', 'tone' => 'bg-brand-soft text-brand', 'bar' => 'before:bg-brand',
                     'title' => 'Məhsulu alan tərəf', 'sub' => 'Alıcı — məhsulu ona satırıq (satış müqaviləsi)', 'partyLabel' => 'Alıcı', 'contractLabel' => 'Satış müqaviləsi',
                     'partyValue' => $holder->counterparty, 'contractValue' => $holder->saleContract],
                    ['party' => 'supplier_id', 'contract' => 'purchase_contract_id', 'kind' => 'purchase', 'role' => 'supplier', 'icon' => 'arrow-down-left', 'tone' => 'bg-saffron-soft text-saffron', 'bar' => 'before:bg-saffron',
                     'title' => 'Məhsulu satan tərəf', 'sub' => 'Satıcı — məhsulu ondan alırıq (alış müqaviləsi)', 'partyLabel' => 'Satıcı', 'contractLabel' => 'Alış müqaviləsi',
                     'partyValue' => $holder->supplier, 'contractValue' => $holder->purchaseContract],
                ] as $side)
                    <section id="{{ $side['kind'] }}" class="scroll-mt-24 card p-6 relative overflow-hidden before:absolute before:inset-x-0 before:top-0 before:h-1 {{ $side['bar'] }}">
                        <div class="flex items-center gap-3 mb-5">
                            <span class="grid place-items-center size-10 rounded-xl {{ $side['tone'] }}"><x-icon :name="$side['icon']" class="size-5"/></span>
                            <div><h2 class="text-base font-semibold">{{ $side['title'] }}</h2><p class="text-xs text-muted">{{ $side['sub'] }}</p></div>
                        </div>
                        <div class="space-y-4">
                            <x-combobox :name="$side['party']" :label="$side['partyLabel']" :url="route('ajax.lookup', ['counterparties', 'role' => $side['role']])"
                                        :value="$side['partyValue']?->id" :display="$side['partyValue']?->name" :placeholder="__('CRM-dən seçin')"/>
                            <x-combobox :name="$side['contract']" :label="$side['contractLabel']" :url="route('ajax.lookup', ['contracts', 'kind' => $side['kind']])"
                                        :depends="$side['party']" :party-id="$side['contractValue']?->counterparty_id"
                                        :value="$side['contractValue']?->id" :display="$side['contractValue'] ? $side['contractValue']->number.' · '.$side['contractValue']->subject : null"
                                        :placeholder="__('Mövcud müqaviləni seçin')"
                                        hint="Siyahıda yalnız seçilmiş tərəfin {{ $side['kind'] === 'sale' ? 'satış' : 'alış' }} müqavilələri görünür. Müqavilə seçsəniz, tərəf avtomatik dolur."/>
                            @if($files)
                                <x-field :label="$side['contractLabel'].' — imzalı PDF'" :name="$side['kind'].'_contract_file'" :hint="__('İstəyə bağlı. Fayl seçilmiş müqavilənin «Fayllar» bölməsinə əlavə olunur.')">
                                    <input type="file" name="{{ $side['kind'] }}_contract_file" accept="application/pdf" class="input !h-auto py-2 text-sm">
                                </x-field>
                                @if($side['contractValue'])
                                    <a href="{{ route('contracts.pdf', $side['contractValue']) }}" target="_blank" class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-ink hover:underline">
                                        <x-icon name="printer" class="size-3.5"/> {{ __('Müqavilə kartını PDF kimi yüklə') }}
                                    </a>
                                @endif
                            @endif
                        </div>
                    </section>
                @endforeach
            </div>
