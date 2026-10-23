{{-- Layout lama 'productionapp' kini hanya membungkus cangkang 3S ONE (layouts.shell);
     bilah aplikasi, sidebar, dan tampilan didefinisikan satu kali di sana.
     Tidak 'narrow': di desktop dashboard & detail PO tampil sebagai tabel, dan
     kolom 768px membuat tabelnya harus digeser ke samping. --}}
@extends('layouts.shell', ['title' => $title ?? '', 'appKey' => 'production'])
