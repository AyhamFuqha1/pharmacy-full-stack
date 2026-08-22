import { useSearchParams } from 'react-router';
import { ReceiptVoucher } from '../components/ReceiptVoucher';

export function ReceiptPage() {
  const [searchParams] = useSearchParams();

  // Get receipt data from URL parameters or use mock data
  const receiptData = {
    receiptNumber: searchParams.get('receiptNumber') || 'RCP-2024-001',
    date: searchParams.get('date') || new Date().toISOString().split('T')[0],
    customerName: searchParams.get('customerName') || 'Sarah Johnson',
    customerPhone: searchParams.get('customerPhone') || '+1 234-567-8901',
    accountBalanceBefore: parseFloat(searchParams.get('accountBalanceBefore') || '249.75'),
    paymentMethod: searchParams.get('paymentMethod') || 'cash',
    amount: parseFloat(searchParams.get('amount') || '125.50'),
    reference: searchParams.get('reference') || undefined,
    linkedInvoice: searchParams.get('linkedInvoice') || 'INV-2024-001',
    remainingBalance: parseFloat(searchParams.get('remainingBalance') || '124.25'),
    notes: searchParams.get('notes') || 'Thank you for your payment.',
  };

  return <ReceiptVoucher {...receiptData} />;
}
