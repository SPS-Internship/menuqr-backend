<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Nota {{ $order->order_code }}</title>

    <style>
        @page {
            margin: 10px 12px;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9px;
            color: #111;
        }

        h1 {
            margin: 0 0 4px;
            font-size: 15px;
            text-align: center;
        }

        p {
            margin: 3px 0;
        }

        .center {
            text-align: center;
        }

        .rule {
            margin: 7px 0;
            border-top: 1px dashed #555;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        td {
            padding: 3px 0;
            vertical-align: top;
        }

        .amount {
            text-align: right;
            white-space: nowrap;
        }

        .total {
            font-size: 11px;
            font-weight: bold;
        }

        .small {
            color: #555;
            font-size: 8px;
        }
    </style>
</head>
<body>
    <h1>{{ $order->restaurant->name }}</h1>

    @if($order->restaurant->address)
        <p class="center">{{ $order->restaurant->address }}</p>
    @endif

    @if($order->restaurant->phone)
        <p class="center">Telp: {{ $order->restaurant->phone }}</p>
    @endif

    <div class="rule"></div>

    <p>No. pesanan: <strong>{{ $order->order_code }}</strong></p>
    <p>Tanggal: {{ $order->created_at->format('d/m/Y H:i') }}</p>
    <p>Meja: {{ $order->table->name ?? '-' }}</p>
    <p>Pelanggan: {{ $order->customer_name ?: '-' }}</p>

    <div class="rule"></div>

    <table>
        @foreach($order->items as $item)
            <tr>
                <td colspan="2">
                    <strong>{{ $item->menu->name ?? 'Menu dihapus' }}</strong>

                    @if($item->variant)
                        <br>
                        <span class="small">
                            Variant: {{ $item->variant->name }}
                        </span>
                    @endif

                    @if($item->note)
                        <br>
                        <span class="small">Catatan: {{ $item->note }}</span>
                    @endif
                </td>
            </tr>

            <tr>
                <td>
                    {{ $item->quantity }} x
                    Rp{{ number_format((float) $item->price, 0, ',', '.') }}
                </td>
                <td class="amount">
                    Rp{{ number_format((float) $item->subtotal, 0, ',', '.') }}
                </td>
            </tr>
        @endforeach
    </table>

    <div class="rule"></div>

    @php
        $subtotal = $order->items->sum('subtotal');
        $discount = (float) ($order->discount ?? 0);
    @endphp

    <table>
        <tr>
            <td>Subtotal</td>
            <td class="amount">
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
                <td class="amount">
                    -Rp{{ number_format($discount, 0, ',', '.') }}
                </td>
            </tr>
        @endif

        <tr>
            <td class="total">TOTAL</td>
            <td class="amount total">
                Rp{{ number_format((float) $order->total, 0, ',', '.') }}
            </td>
        </tr>
    </table>

    @if($order->note)
        <div class="rule"></div>
        <p>Catatan pesanan: {{ $order->note }}</p>
    @endif

    <div class="rule"></div>

    <p>Status pembayaran: {{ strtoupper($order->payment_status ?? 'unpaid') }}</p>
    <p class="center">Terima kasih atas pesanan Anda</p>
</body>
</html>