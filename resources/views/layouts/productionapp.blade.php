{{-- Layout lama 'productionapp' kini hanya membungkus cangkang 3S ONE (layouts.shell);
     bilah aplikasi, sidebar, dan tampilan didefinisikan satu kali di sana. --}}
@extends('layouts.shell', ['title' => $title ?? '', 'appKey' => 'production', 'narrow' => true])
