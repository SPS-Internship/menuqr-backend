
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Nota {{ $order->order_code }}</title>

    <style>
        @page {
            margin: 3mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            width: 100%;
            margin: 0;
            padding: 0;
            font-family: DejaVu Sans, sans-serif;
            font-size: 8px;
            line-height: 1.4;
            color: #000;
        }

        h1 {
            margin: 0 0 4px;
            font-size: 13px;
            text-align: center;
            font-weight: bold;
            word-wrap: break-word;
        }

        p {
            margin: 3px 0;
            word-wrap: break-word;
        }

        .center {
            text-align: center;
        }

        .rule {
            margin: 6px 0;
            border-top: 1px dashed #000;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        td {
            padding: 3px 0;
            vertical-align: top;
            word-wrap: break-word;
        }

        .item-name {
            font-weight: bold;
            word-wrap: break-word;
        }

        .detail {
            font-size: 7px;
            color: #333;
        }

        .price {
            width: 42%;
            text-align: right;
            white-space: nowrap;
        }

        .total {
            font-size: 10px;
            font-weight: bold;
        }

        .footer {
            margin-top: 8px;
            text-align: center;
            font-size: 8px;
        }

        tr {
            page-break-inside: avoid;
        }
    </style>
</head>

<body>

    {{-- HEADER RESTORAN --}}
    <h1>{{ $order->restaurant->name }}</h1>

    @if($order->restaurant->address)
        <p class="center">
            {{ $order->restaurant->address }}
        </p>
    @endif

    @if($order->restaurant->phone)
        <p class="center">
            Telp: {{ $order->restaurant->phone }}
        </p>
    @endif

    <div class="rule"></div>

    {{-- INFORMASI PESANAN --}}
    <p>
        No: <strong>{{ $order->order_code }}</strong>
    </p>

    <p>
        Tanggal: {{ $order->created_at->format('d/m/Y H:i') }}
    </p>

    <p>
        Meja: {{ $order->table->name ?? '-' }}
    </p>

    <p>
        Pelanggan: {{ $order->customer_name ?: '-' }}
    </p>

    <div class="rule"></div>

    {{-- DETAIL MENU --}}
    <table>
        @foreach($order->items as $item)

            <tr>
                <td colspan="2">
                    <div class="item-name">
                        {{ $item->menu->name ?? 'Menu dihapus' }}
                    </div>

                    @if($item->variant)
                        <div class="detail">
                            Varian: {{ $item->variant->name }}
                        </div>
                    @endif

                    @if($item->note)
                        <div class="detail">
                            Catatan: {{ $item->note }}
                        </div>
                    @endif
                </td>
            </tr>

            <tr>
                <td>
                    {{ $item->quantity }} x
                    Rp{{ number_format((float) $item->price, 0, ',', '.') }}
                </td>

                <td class="price">
                    Rp{{ number_format((float) $item->subtotal, 0, ',', '.') }}
                </td>
            </tr>

        @endforeach
    </table>

    <div class="rule"></div>

    {{-- TOTAL PEMBAYARAN --}}
    @php
        $subtotal = $order->items->sum('subtotal');
        $discount = (float) ($order->discount ?? 0);
    @endphp

    <table>
        <tr>
            <td>Subtotal</td>

            <td class="price">
                Rp{{ number_format((float) $subtotal, 0, ',', '.') }}
            </td>
        </tr>

        @if($discount > 0)
            <tr>
                <td>
                    Diskon
                    @if($order->promo)
                        ({{ $order->promo->name }})
                    @endif
                </td>

                <td class="price">
                    -Rp{{ number_format($discount, 0, ',', '.') }}
                </td>
            </tr>
        @endif

        <tr>
            <td class="total">TOTAL</td>

            <td class="price total">
                Rp{{ number_format((float) $order->total, 0, ',', '.') }}
            </td>
        </tr>
    </table>

    @if($order->note)
        <div class="rule"></div>

        <p>
            Catatan pesanan: {{ $order->note }}
        </p>
    @endif

    <div class="rule"></div>

    {{-- STATUS PEMBAYARAN --}}
    <p>
        Status pembayaran:
        <strong>
            {{ strtoupper($order->payment_status ?? 'unpaid') }}
        </strong>
    </p>

    <div class="footer">
        <p>Terima kasih atas pesanan Anda</p>
        <p>Selamat menikmati!</p>
    </div>

</body>
</html>
