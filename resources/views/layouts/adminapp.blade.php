{{-- Layout lama 'adminapp' kini hanya membungkus cangkang 3S ONE (layouts.shell);
     bilah aplikasi, sidebar, dan tampilan didefinisikan satu kali di sana. --}}
@extends('layouts.shell', ['title' => $title ?? '', 'appKey' => 'admin'])
