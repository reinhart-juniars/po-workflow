<section class="report-action-bar">
  <div class="report-action-text">
    {{ $caption ?? 'Export laporan sesuai filter aktif ke format Excel atau PDF.' }}
  </div>
  <div class="flex flex-wrap items-center gap-2">
    @if(!empty($excelUrl))
      <a href="{{ $excelUrl }}" class="btn-export-excel">
        Export Excel
      </a>
    @endif
    @if(!empty($pdfUrl))
      <a href="{{ $pdfUrl }}" class="btn-export-pdf">
        Export PDF
      </a>
    @endif
  </div>
</section>
