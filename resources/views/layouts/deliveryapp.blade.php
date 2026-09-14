{{-- Layout lama 'deliveryapp' kini hanya membungkus cangkang 3S BCS (layouts.shell);
     bilah aplikasi, sidebar, dan tampilan didefinisikan satu kali di sana. --}}
@extends('layouts.shell', ['title' => $title ?? '', 'appKey' => 'delivery', 'narrow' => true])
