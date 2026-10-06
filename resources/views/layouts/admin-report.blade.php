{{-- Layout lama 'admin-report' kini hanya membungkus cangkang 3S ONE (layouts.shell);
     bilah aplikasi, sidebar, dan tampilan didefinisikan satu kali di sana.
     Laporan lebar: sidebar ditutup sementara supaya tabel dapat seluruh layar. --}}
@extends('layouts.shell', ['title' => $title ?? '', 'appKey' => 'admin', 'wide' => true, 'collapsed' => true])
