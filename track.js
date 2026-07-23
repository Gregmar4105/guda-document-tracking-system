// Print just the QR Routing Slip
function printQR() {
    document.body.classList.add('print-qr-only');
    window.print();
    setTimeout(() => document.body.classList.remove('print-qr-only'), 500);
}

// Print the whole history timeline
function printHistory() {
    document.body.classList.add('print-history-only');
    window.print();
    setTimeout(() => document.body.classList.remove('print-history-only'), 500);
}