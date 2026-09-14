{{-- Layout lama 'accountingapp' kini hanya membungkus cangkang 3S BCS (layouts.shell);
     menu & tampilan didefinisikan satu kali di sana. --}}
@extends('layouts.shell', ['title' => $title ?? ''])
