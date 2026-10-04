<x-layouts.app title="Abunə">
    <div class="max-w-xl mx-auto mt-10">
        <x-empty icon="lock" title="Abunə müddəti bitib və ya dayandırılıb"
                 :text="'«'.$company->name.'» üçün sistemdən istifadə müvəqqəti məhdudlaşdırılıb. Məlumatlarınız qorunur. Abunəni bərpa etmək üçün TradeFlow dəstəyi ilə əlaqə saxlayın.'">
            <a href="mailto:{{ config('mail.from.address') }}" class="btn btn-primary"><x-icon name="mail" class="size-4"/> Dəstəyə yaz</a>
        </x-empty>
    </div>
</x-layouts.app>
