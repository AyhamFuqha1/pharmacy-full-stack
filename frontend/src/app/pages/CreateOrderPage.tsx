import { useEffect, useMemo, useRef, useState } from 'react';
import { ArrowLeft, Plus, Scan, Trash2 } from 'lucide-react';
import { useNavigate } from 'react-router';
import { ApiClientError } from '../../api/client';
import { customersApi } from '../../api/customers';
import { productsApi } from '../../api/products';
import { salesApi } from '../../api/sales';
import { useAuth } from '../../auth/useAuth';
import type { CustomerDto } from '../../types/party';
import type { BarcodeLookupDto, ProductDto } from '../../types/product';
import type { SalesLinePayload, SalesPaymentMethod, SalesPayload } from '../../types/sales';
import { formatMoney } from '../../utils/batchFormatters';
type UnitChoice = {
  id: number | null;
  name: string;
  symbol: string;
  factor: number;
  price: number;
};
type SaleLine = {
  key: number;
  product_id: string;
  barcode?: string;
  amount: string;
  unit_id: string;
  units: UnitChoice[];
  normal_price: string;
  price: string;
  discount_type: 'fixed' | 'percentage';
  discount_value: string;
  discount_reason: string;
  available: number;
  loading: boolean;
};
const emptyLine = (key: number): SaleLine => ({
  key,
  product_id: '',
  amount: '1',
  unit_id: '',
  units: [],
  normal_price: '0',
  price: '0',
  discount_type: 'fixed',
  discount_value: '0',
  discount_reason: '',
  available: 0,
  loading: false
});
export function CreateOrderPage() {
  const navigate = useNavigate(),
    {
      hasPermission
    } = useAuth(),
    canOverride = hasPermission('sales.override_price'),
    canDiscount = hasPermission('sales.discount');
  const [customers, setCustomers] = useState<CustomerDto[]>([]),
    [products, setProducts] = useState<ProductDto[]>([]),
    [loading, setLoading] = useState(true),
    [customerId, setCustomerId] = useState(''),
    [date, setDate] = useState(new Date().toISOString().slice(0, 10)),
    [paymentMethod, setPaymentMethod] = useState<SalesPaymentMethod>('cash'),
    [paidAmount, setPaidAmount] = useState('0'),
    [lines, setLines] = useState<SaleLine[]>([emptyLine(1)]),
    [discountType, setDiscountType] = useState<'fixed' | 'percentage'>('fixed'),
    [discountValue, setDiscountValue] = useState('0'),
    [discountReason, setDiscountReason] = useState(''),
    [cheque, setCheque] = useState({
      number: '',
      bank: '',
      due_date: ''
    }),
    [barcode, setBarcode] = useState(''),
    [error, setError] = useState<ApiClientError | null>(null),
    [submitting, setSubmitting] = useState(false);
  const [customerSummary, setCustomerSummary] = useState<CustomerDto | null>(null),
    [customerLoading, setCustomerLoading] = useState(false),
    [customerError, setCustomerError] = useState(''),
    [creditOverrideReason, setCreditOverrideReason] = useState('');
  const customerRequest = useRef(0);
  useEffect(() => {
    const c = new AbortController();
    Promise.all([loadCustomers(c.signal), loadProducts(c.signal)]).then(([a, b]) => {
      setCustomers(a);
      setProducts(b);
    }).catch(v => setError(v instanceof ApiClientError ? v : new ApiClientError(0, 'Unable to load sale choices.'))).finally(() => setLoading(false));
    return () => c.abort();
  }, []);
  useEffect(() => {
    void selectCustomer(customerId);
  }, [customerId]);
  const lineNet = (line: SaleLine) => {
    const gross = Number(line.amount) * Number(line.price),
      value = Number(line.discount_value || 0);
    return Math.max(0, gross - (line.discount_type === 'percentage' ? gross * value / 100 : value));
  };
  const subtotal = useMemo(() => lines.reduce((sum, line) => sum + lineNet(line), 0), [lines]),
    invoiceDiscount = discountType === 'percentage' ? subtotal * Number(discountValue || 0) / 100 : Number(discountValue || 0),
    total = Math.max(0, subtotal - invoiceDiscount);
  const patchLine = (key: number, values: Partial<SaleLine>) => setLines(current => current.map(line => line.key === key ? {
    ...line,
    ...values
  } : line));
  async function chooseProduct(key: number, productId: string, barcodeValue?: string) {
    patchLine(key, {
      product_id: productId,
      barcode: barcodeValue,
      unit_id: '',
      units: [],
      normal_price: '0',
      price: '0',
      discount_type: 'fixed',
      discount_value: '0',
      discount_reason: '',
      available: 0,
      loading: true
    });
    const product = products.find(p => p.id === Number(productId));
    if (!product) {
      patchLine(key, {
        loading: false
      });
      return;
    }
    try {
      const info = (await productsApi.lookupBarcode(product.barcode)).data;
      const choices: UnitChoice[] = info.sale_units.map(unit => ({
          id: unit.id,
          name: unit.name,
          symbol: unit.symbol,
          factor: Number(unit.base_quantity),
          price: Number(unit.sale_price ?? 0)
        })),
        base = info.base_unit ?? info.sale_units.find(unit => Boolean(unit.is_base));
      if (!base) {
        patchLine(key, {
          loading: false,
          available: Number(info.available_sellable_stock || 0)
        });
        setError(new ApiClientError(422, `${product.name} has no base sale unit configured. Add one in Product Units / Packaging before selling it.`));
        return;
      }
      const basePrice = Number(base.sale_price ?? info.default_price);
      patchLine(key, {
        units: choices,
        unit_id: String(base.id),
        normal_price: String(basePrice),
        price: String(basePrice),
        available: Number(info.available_sellable_stock || 0),
        loading: false
      });
    } catch (value) {
      patchLine(key, {
        loading: false
      });
      setError(value instanceof ApiClientError ? value : new ApiClientError(0, 'Unable to load product pricing.'));
    }
  }
  function chooseUnit(line: SaleLine, value: string) {
    const selected = line.units.find(unit => String(unit.id ?? '') === value) ?? line.units[0],
      price = String(selected?.price ?? 0);
    patchLine(line.key, {
      unit_id: value,
      normal_price: price,
      price
    });
  }
  async function scan() {
    if (!barcode.trim()) return;
    try {
      const info: BarcodeLookupDto = (await productsApi.lookupBarcode(barcode.trim())).data;
      const key = Date.now();
      setLines(current => [...current.filter(line => line.product_id), emptyLine(key)]);
      setBarcode('');
      setTimeout(() => void chooseProduct(key, String(info.product.id), info.product.barcode), 0);
    } catch (value) {
      setError(value instanceof ApiClientError ? value : new ApiClientError(0, 'Barcode lookup failed.'));
    }
  }
  async function selectCustomer(value: string) {
    const request = ++customerRequest.current;
    setCustomerId(value);
    setCustomerSummary(null);
    setCustomerError('');
    setCreditOverrideReason('');
    if (!value) {
      setCustomerLoading(false);
      return;
    }
    setCustomerLoading(true);
    try {
      const summary = (await customersApi.detail(Number(value))).data;
      if (request === customerRequest.current) setCustomerSummary(summary);
    } catch (value) {
      if (request === customerRequest.current) setCustomerError(value instanceof ApiClientError && value.status === 403 ? 'Credit information is unavailable for your role.' : 'Unable to load customer credit information.');
    } finally {
      if (request === customerRequest.current) setCustomerLoading(false);
    }
  }
  async function complete() {
    if (!customerId || !lines.length || lines.some(line => !line.product_id || Number(line.amount) < 1) || total <= 0 || Number(paidAmount) < 0 || Number(paidAmount) > total) {
      setError(new ApiClientError(422, 'Check the customer, items, quantities, discounts and paid amount.'));
      return;
    }
    if (Number(discountValue) > 0 && !discountReason.trim()) {
      setError(new ApiClientError(422, 'Invoice discount reason is required.'));
      return;
    }
    if (paymentMethod === 'cheque' && (!cheque.number || !cheque.bank || !cheque.due_date)) {
      setError(new ApiClientError(422, 'Cheque number, bank and due date are required.'));
      return;
    }
    setSubmitting(true);
    setError(null);
    const payload: SalesPayload = {
      customer_id: Number(customerId),
      date,
      discount: invoiceDiscount,
      invoice_discount_type: discountType,
      invoice_discount_value: Number(discountValue || 0),
      invoice_discount_reason: discountReason || undefined,
      paid_amount: Number(paidAmount || 0),
      payment_method: paymentMethod,
      cheque_number: cheque.number || undefined,
      cheque_bank: cheque.bank || undefined,
      cheque_due_date: cheque.due_date || undefined,
      credit_override_reason: creditOverrideReason || undefined,
      items: lines.map((line): SalesLinePayload => ({
        product_id: Number(line.product_id),
        amount: Number(line.amount),
        ...(line.unit_id ? {
          unit_id: Number(line.unit_id)
        } : {}),
        price: Number(line.price),
        discount: 0,
        discount_type: line.discount_type,
        discount_value: Number(line.discount_value || 0),
        discount_reason: line.discount_reason || undefined
      }))
    };
    try {
      const response = await salesApi.create(payload);
      navigate(`/sales/${response.data.id}`, {
        replace: true,
        state: {
          success: 'Sale completed with FEFO inventory and accounting posting.'
        }
      });
    } catch (value) {
      setError(value instanceof ApiClientError ? value : new ApiClientError(0, 'Unable to complete sale.'));
    } finally {
      setSubmitting(false);
    }
  }
  return <div className="p-4 sm:p-8 space-y-6"><button onClick={() => navigate('/sales')} className="flex gap-2 text-gray-600"><ArrowLeft /> Back to Sales</button><div><h1 className="text-3xl font-bold">Create Sale</h1><p className="text-gray-500">Package pricing is loaded automatically; Laravel remains authoritative for FEFO and totals.</p></div>{error && <div className="p-4 bg-red-50 border border-red-200 rounded-xl text-red-800">{error.message}</div>}<section className="bg-white border rounded-2xl p-5 space-y-4"><label><span className="block text-sm font-medium mb-2">Customer</span><select className="input" disabled={loading} value={customerId} onChange={e => setCustomerId(e.target.value)}><option value="">Select customer</option>{customers.map(customer => <option key={customer.id} value={customer.id}>{customer.name}{customer.phone_num ? ` — ${customer.phone_num}` : ''}</option>)}</select></label>{customerLoading && <p className="text-sm text-gray-500">Loading customer credit…</p>}{customerError && <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">{customerError} The sale form remains available.</p>}{customerSummary && <div className="grid gap-3 sm:grid-cols-4"><Credit label="Current Receivable" value={formatMoney(Number(customerSummary.current_receivable || 0))} /><Credit label="Credit Limit" value={customerSummary.credit_limit == null ? 'Not configured' : formatMoney(Number(customerSummary.credit_limit))} /><Credit label="Available Credit" value={customerSummary.available_credit == null ? '—' : formatMoney(Number(customerSummary.available_credit))} /><Credit label="Credit Status" value={customerSummary.exceeded ? 'Exceeded' : customerSummary.warning ? 'Near limit' : 'Within limit'} tone={customerSummary.exceeded ? 'danger' : customerSummary.warning ? 'warning' : 'normal'} /></div>}{customerSummary?.exceeded && hasPermission('customer.credit_override') && <Field label="Credit Override Reason"><input className="input" value={creditOverrideReason} onChange={e => setCreditOverrideReason(e.target.value)} /></Field>}</section><section className="bg-teal-50 border border-teal-200 rounded-2xl p-5"><div className="flex gap-2 items-center mb-3"><Scan /><strong>Barcode</strong></div><form className="flex gap-2" onSubmit={e => {
        e.preventDefault();
        void scan();
      }}><input className="input bg-white" value={barcode} onChange={e => setBarcode(e.target.value)} placeholder="Scan product barcode" /><button className="report-action primary">Add</button></form></section><section className="bg-white border rounded-2xl overflow-hidden"><div className="p-5 border-b flex justify-between"><div><h2 className="font-bold text-lg">Sale Items</h2><p className="text-sm text-gray-500">Each line keeps its own product, package, price and discount.</p></div><button className="report-action primary" onClick={() => setLines(value => [...value, emptyLine(Date.now())])}><Plus size={16} /> Add Item</button></div><div className="grid gap-4 p-4">{lines.map(line => {
          const selected = line.units.find(unit => String(unit.id ?? '') === line.unit_id) ?? line.units[0];
          return <div className="grid gap-3 rounded-xl border p-4 lg:grid-cols-7" key={line.key}><Field label="Product"><select className="input" value={line.product_id} onChange={e => void chooseProduct(line.key, e.target.value)}><option value="">Select product</option>{products.map(product => <option key={product.id} value={product.id}>{product.name}</option>)}</select></Field><Field label="Quantity"><input className="input" type="number" min="1" step="1" value={line.amount} onChange={e => patchLine(line.key, {
                amount: e.target.value
              })} /></Field><Field label="Unit / Packaging"><select className="input" disabled={!line.units.length || line.loading} value={line.unit_id} onChange={e => chooseUnit(line, e.target.value)}>{line.units.map(unit => <option key={unit.id ?? 'base'} value={unit.id ?? ''}>{unit.name} ({unit.factor} base)</option>)}</select></Field><Field label="Unit Price"><input className="input" type="number" min="0" step="0.01" readOnly={!canOverride} value={line.price} onChange={e => patchLine(line.key, {
                price: e.target.value
              })} /></Field><Field label="Discount"><input className="input" type="number" min="0" step="0.01" readOnly={!canDiscount} value={line.discount_value} onChange={e => patchLine(line.key, {
                discount_value: e.target.value
              })} /></Field><Field label="Discount Type"><select className="input" disabled={!canDiscount} value={line.discount_type} onChange={e => patchLine(line.key, {
                discount_type: e.target.value as SaleLine['discount_type']
              })}><option value="fixed">Fixed</option><option value="percentage">Percentage</option></select></Field><div><p className="text-sm text-gray-500">Line Total</p><strong>{formatMoney(lineNet(line))}</strong><button className="block mt-3 text-red-600" onClick={() => setLines(value => value.filter(item => item.key !== line.key))}><Trash2 size={17} /></button></div><div className="lg:col-span-7 text-sm text-gray-600">Available: {line.available} {line.units[0]?.symbol || 'base units'}{selected && selected.factor > 1 ? ` · ${Math.floor(line.available / selected.factor)} ${selected.name}` : ''}</div>{Number(line.discount_value) > 0 && <Field label="Line Discount Reason"><input required className="input" value={line.discount_reason} onChange={e => patchLine(line.key, {
                discount_reason: e.target.value
              })} /></Field>}</div>;
        })}</div></section><section className="grid gap-5 lg:grid-cols-2"><div className="bg-white border rounded-2xl p-5 grid gap-4 sm:grid-cols-2"><Field label="Sale Date"><input className="input" type="date" value={date} onChange={e => setDate(e.target.value)} /></Field><Field label="Payment Method"><select className="input" value={paymentMethod} onChange={e => setPaymentMethod(e.target.value as SalesPaymentMethod)}><option value="cash">Cash</option><option value="cheque">Cheque</option><option value="visa">Visa / Bank</option></select></Field><Field label="Amount Paid"><input className="input" type="number" min="0" step="0.01" value={paidAmount} onChange={e => setPaidAmount(e.target.value)} /></Field>{paymentMethod === 'cheque' && <><Field label="Cheque Number"><input className="input" value={cheque.number} onChange={e => setCheque({
              ...cheque,
              number: e.target.value
            })} /></Field><Field label="Bank"><input className="input" value={cheque.bank} onChange={e => setCheque({
              ...cheque,
              bank: e.target.value
            })} /></Field><Field label="Due Date"><input className="input" type="date" value={cheque.due_date} onChange={e => setCheque({
              ...cheque,
              due_date: e.target.value
            })} /></Field></>}</div><div className="bg-white border rounded-2xl p-5"><div className="grid gap-3 sm:grid-cols-2"><Field label="Invoice Discount Type"><select className="input" disabled={!canDiscount} value={discountType} onChange={e => setDiscountType(e.target.value as typeof discountType)}><option value="fixed">Fixed</option><option value="percentage">Percentage</option></select></Field><Field label="Invoice Discount Value"><input className="input" readOnly={!canDiscount} type="number" min="0" step="0.01" value={discountValue} onChange={e => setDiscountValue(e.target.value)} /></Field>{Number(discountValue) > 0 && <Field label="Invoice Discount Reason"><input className="input" value={discountReason} onChange={e => setDiscountReason(e.target.value)} /></Field>}</div><Summary label="Subtotal after line discounts" value={subtotal} /><Summary label="Invoice Discount" value={invoiceDiscount} /><Summary label="Total" value={total} /><Summary label="Amount Paid" value={Number(paidAmount || 0)} /><Summary label="Balance Due" value={Math.max(0, total - Number(paidAmount || 0))} /><button disabled={submitting || loading} className="w-full mt-5 py-3 rounded-xl bg-teal-600 text-white disabled:opacity-50" onClick={() => void complete()}>{submitting ? 'Completing Sale…' : 'Complete Sale'}</button></div></section></div>;
}
function Field({
  label,
  children
}: {
  label: string;
  children: React.ReactNode;
}) {
  return <label><span className="block text-sm font-medium mb-2">{label}</span>{children}</label>;
}
function Summary({
  label,
  value
}: {
  label: string;
  value: number;
}) {
  return <p className="flex justify-between py-2 border-b"><span>{label}</span><strong>{formatMoney(value)}</strong></p>;
}
function Credit({label,value,tone='normal'}:{label:string;value:string;tone?:'normal'|'warning'|'danger'}) {
  return <div className={`rounded-xl p-3 ${tone==='danger'?'bg-red-50 text-red-800':tone==='warning'?'bg-amber-50 text-amber-800':'bg-gray-50'}`}><p className="text-xs opacity-70">{label}</p><strong>{value}</strong></div>;
}
async function loadCustomers(signal: AbortSignal) {
  const rows: CustomerDto[] = [];
  for (let page = 1;; page++) {
    const response = await customersApi.list({
      page,
      per_page: 100
    }, signal);
    rows.push(...response.data);
    if (page >= (response.meta.last_page ?? page)) return rows;
  }
}
async function loadProducts(signal: AbortSignal) {
  const rows: ProductDto[] = [];
  for (let page = 1;; page++) {
    const response = await productsApi.list({
      page,
      per_page: 100,
      sort: 'name',
      direction: 'asc'
    }, signal);
    rows.push(...response.data);
    if (page >= (response.meta.last_page ?? page)) return rows;
  }
}
