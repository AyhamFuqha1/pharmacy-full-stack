import { QrCode, Printer } from 'lucide-react';
import { formatMoney } from '../../utils/batchFormatters';
import type { JsonRecord } from '../../api/erpOperations';
import { PrintIdentityHeader } from './PrintIdentityHeader';

interface ReceiptVoucherProps {
  printIdentity: JsonRecord;
  receiptNumber: string;
  date: string;
  customerName: string;
  customerPhone: string;
  accountBalanceBefore: number;
  paymentMethod: string;
  amount: number;
  reference?: string;
  linkedInvoice?: string;
  remainingBalance: number;
  notes?: string;
}

export function ReceiptVoucher({
  printIdentity,
  receiptNumber,
  date,
  customerName,
  customerPhone,
  accountBalanceBefore,
  paymentMethod,
  amount,
  reference,
  linkedInvoice,
  remainingBalance,
  notes,
}: ReceiptVoucherProps) {
  const handlePrint = () => {
    window.print();
  };

  return (
    <div className="min-h-screen bg-gray-100 p-8">
      {/* Print Button - Hidden when printing */}
      <div className="max-w-4xl mx-auto mb-4 print:hidden">
        <button
          onClick={handlePrint}
          className="flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-teal-600 to-emerald-500 text-white rounded-xl hover:from-teal-700 hover:to-emerald-600 transition-all shadow-sm hover:shadow-md font-semibold"
        >
          <Printer className="w-5 h-5" />
          <span>Print Receipt</span>
        </button>
      </div>

      {/* Receipt Container */}
      <div className="max-w-4xl mx-auto bg-white shadow-lg print:shadow-none">
        {/* Header Section */}
        <div className="border-b-4 border-teal-600 p-8">
          <PrintIdentityHeader identity={printIdentity} />
          <div className="flex items-start justify-between">
            {/* Right: Receipt Info */}
            <div className="text-right">
              <div className="inline-block px-4 py-2 bg-teal-50 border-2 border-teal-600 rounded-lg mb-3">
                <h2 className="text-lg font-bold text-teal-700">PAYMENT RECEIPT</h2>
              </div>
              <div className="space-y-1">
                <div>
                  <span className="text-sm text-gray-500">Receipt No:</span>
                  <span className="ml-2 font-mono font-bold text-gray-900">{receiptNumber}</span>
                </div>
                <div>
                  <span className="text-sm text-gray-500">Date:</span>
                  <span className="ml-2 font-semibold text-gray-900">{date}</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* Customer Info Section */}
        <div className="p-8 border-b border-gray-200">
          <h3 className="text-sm font-semibold text-gray-500 uppercase mb-3">Customer Information</h3>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <p className="text-sm text-gray-500 mb-1">Customer Name</p>
              <p className="text-lg font-bold text-gray-900">{customerName}</p>
            </div>
            <div>
              <p className="text-sm text-gray-500 mb-1">Phone Number</p>
              <p className="text-lg font-semibold text-gray-900">{customerPhone}</p>
            </div>
            <div>
              <p className="text-sm text-gray-500 mb-1">Account Balance Before Payment</p>
              <p className="text-lg font-bold text-red-600">{formatMoney(accountBalanceBefore)}</p>
            </div>
          </div>
        </div>

        {/* Payment Details & Summary */}
        <div className="p-8 border-b border-gray-200">
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
            {/* Left: Payment Details */}
            <div className="lg:col-span-2">
              <h3 className="text-sm font-semibold text-gray-500 uppercase mb-4">Payment Details</h3>
              <div className="space-y-4">
                <div className="flex items-center justify-between py-2 border-b border-gray-100">
                  <span className="text-gray-600">Payment Method</span>
                  <span className="font-semibold text-gray-900 uppercase">{paymentMethod}</span>
                </div>
                
                {reference && (
                  <div className="flex items-center justify-between py-2 border-b border-gray-100">
                    <span className="text-gray-600">
                      {paymentMethod === 'cheque' ? 'Cheque Number' : 'Transaction Reference'}
                    </span>
                    <span className="font-mono font-semibold text-gray-900">{reference}</span>
                  </div>
                )}

                {linkedInvoice && (
                  <div className="flex items-center justify-between py-2 border-b border-gray-100">
                    <span className="text-gray-600">Linked Invoice</span>
                    <span className="font-mono font-semibold text-gray-900">{linkedInvoice}</span>
                  </div>
                )}

                <div className="flex items-center justify-between py-2">
                  <span className="text-gray-600">Payment Date</span>
                  <span className="font-semibold text-gray-900">{date}</span>
                </div>
              </div>
            </div>

            {/* Right: Summary Box */}
            <div className="bg-gradient-to-br from-teal-50 to-emerald-50 border-2 border-teal-200 rounded-xl p-6">
              <h3 className="text-sm font-semibold text-teal-700 uppercase mb-4">Payment Summary</h3>
              <div className="space-y-4">
                <div>
                  <p className="text-sm text-teal-600 mb-1">Paid Amount</p>
                  <p className="text-3xl font-bold text-teal-700">{formatMoney(amount)}</p>
                </div>
                <div className="pt-4 border-t-2 border-teal-300">
                  <p className="text-sm text-teal-600 mb-1">Remaining Balance</p>
                  <p className={`text-2xl font-bold ${remainingBalance > 0 ? 'text-red-600' : 'text-emerald-600'}`}>
                    ${Math.abs(remainingBalance).toFixed(2)}
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* Notes Section */}
        {notes && (
          <div className="p-8 border-b border-gray-200 bg-gray-50">
            <h3 className="text-sm font-semibold text-gray-500 uppercase mb-2">Notes</h3>
            <p className="text-gray-700">{notes}</p>
          </div>
        )}

        {/* Footer Section */}
        <div className="p-8">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-8">
            {/* Signature Section */}
            <div>
              <div className="mb-2">
                <p className="text-sm text-gray-500 mb-8">Received by:</p>
                <div className="border-t-2 border-gray-300 pt-2">
                  <p className="text-sm text-gray-600">Authorized Signature</p>
                </div>
              </div>
              <div className="mt-8">
                <p className="text-sm text-gray-500 mb-8">Customer Signature:</p>
                <div className="border-t-2 border-gray-300 pt-2">
                  <p className="text-sm text-gray-600">Signature</p>
                </div>
              </div>
            </div>

            {/* QR Code Section */}
            <div className="flex flex-col items-end justify-center">
              <div className="w-32 h-32 bg-gray-100 border-2 border-gray-300 rounded-lg flex items-center justify-center mb-2">
                <QrCode className="w-24 h-24 text-gray-400" />
              </div>
              <p className="text-xs text-gray-500 text-center">Scan for verification</p>
            </div>
          </div>

          {/* Legal Footer */}
          <div className="mt-8 pt-6 border-t border-gray-200 text-center">
            <p className="text-xs text-gray-500">
              This is a computer-generated receipt and is valid without signature.
            </p>
            <p className="text-xs text-gray-400 mt-1">
              This document uses the configured print identity shown above.
            </p>
          </div>
        </div>
      </div>

      {/* Print Styles */}
      <style>{`
        @media print {
          body {
            background: white;
          }
          .print\\:hidden {
            display: none !important;
          }
          .print\\:shadow-none {
            box-shadow: none !important;
          }
        }
      `}</style>
    </div>
  );
}
