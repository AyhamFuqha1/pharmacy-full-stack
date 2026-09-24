import { useEffect, useState } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router';
import { ArrowLeft, Edit, Image as ImageIcon, Package, ShieldAlert, Trash2 } from 'lucide-react';
import { ApiClientError } from '../../api/client';
import { batchesApi } from '../../api/batches';
import { productImageUrl, productsApi } from '../../api/products';
import { PermissionGate } from '../../auth/PermissionGate';
import { useAuth } from '../../auth/useAuth';
import type { BatchDto } from '../../types/batch';
import type { ProductDto } from '../../types/product';
import { batchStatus, classifyExpiry, formatDateOnly, formatMoney } from '../../utils/batchFormatters';
import { StatusBadge } from './BatchesPage';
import { ProductReporting } from '../components/ProductReporting';

export function ProductDetailsPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const location = useLocation();
  const { hasPermission } = useAuth();
  const productId = Number(id);
  const [product, setProduct] = useState<ProductDto | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<ApiClientError | null>(null);
  const [reloadKey, setReloadKey] = useState(0);
  const [confirmingDelete, setConfirmingDelete] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);
  const [batches, setBatches] = useState<BatchDto[]>([]);
  const [batchesLoading, setBatchesLoading] = useState(false);
  const [batchesError, setBatchesError] = useState<ApiClientError | null>(null);

  useEffect(() => {
    if (!Number.isInteger(productId) || productId <= 0) {
      setError(new ApiClientError(404, 'Product not found.'));
      setIsLoading(false);
      return;
    }

    const controller = new AbortController();
    setIsLoading(true);
    setError(null);
    productsApi.detail(productId, controller.signal, new URLSearchParams(location.search).get('archive_status') === 'archived' ? 'archived' : undefined)
      .then((response) => setProduct(response.data))
      .catch((requestError: unknown) => {
        if (requestError instanceof DOMException && requestError.name === 'AbortError') return;
        setError(requestError instanceof ApiClientError ? requestError : new ApiClientError(0, 'Unable to load the product.'));
      })
      .finally(() => { if (!controller.signal.aborted) setIsLoading(false); });
    return () => controller.abort();
  }, [productId, reloadKey]);

  useEffect(() => {
    if (!Number.isInteger(productId) || productId <= 0 || !hasPermission('batches.manage')) return;
    const controller = new AbortController();
    setBatchesLoading(true); setBatchesError(null);
    batchesApi.listAll({ product_id: productId, sort: 'expiry_date', direction: 'asc' }, controller.signal)
      .then(setBatches)
      .catch((requestError: unknown) => {
        if (requestError instanceof DOMException && requestError.name === 'AbortError') return;
        setBatchesError(requestError instanceof ApiClientError ? requestError : new ApiClientError(0, 'Unable to load product batches.'));
      })
      .finally(() => { if (!controller.signal.aborted) setBatchesLoading(false); });
    return () => controller.abort();
  }, [hasPermission, productId]);

  async function deleteProduct() {
    if (!product) return;
    setIsDeleting(true);
    try {
      await productsApi.delete(product.id);
      navigate('/products', { replace: true, state: { success: `${product.name} was deleted.` } });
    } catch (requestError) {
      setConfirmingDelete(false);
      setError(requestError instanceof ApiClientError ? requestError : new ApiClientError(0, 'Unable to delete the product.'));
    } finally {
      setIsDeleting(false);
    }
  }

  if (isLoading) return <DetailLoading />;

  if (error || !product) {
    const notFound = error?.status === 404;
    return <div className="p-8"><button onClick={() => navigate('/products')} className="flex items-center gap-2 text-gray-600 mb-6"><ArrowLeft className="w-5 h-5" /> Back to Products</button><div className="bg-white rounded-2xl border border-gray-100 p-12 text-center"><Package className="w-14 h-14 text-gray-300 mx-auto mb-4" /><h1 className="text-2xl font-bold text-gray-900 mb-2">{notFound ? 'Product not found' : error?.status === 403 ? 'Products access denied' : 'Unable to load product'}</h1><p className="text-gray-500 mb-6">{error?.message}</p>{!notFound && error?.status !== 403 && <button onClick={() => setReloadKey((key) => key + 1)} className="px-5 py-2.5 bg-teal-600 text-white rounded-xl">Retry</button>}</div></div>;
  }

  const image = productImageUrl(product.img);
  const totalQuantity = batches.reduce((total, batch) => total + batch.quantity, 0);
  const activeBatches = batches.filter((batch) => batch.quantity > 0 && classifyExpiry(batch.expiry_date) !== 'expired');
  const nearestExpiry = activeBatches[0]?.expiry_date;

  return (
    <div className="p-8">
      <button onClick={() => navigate('/products')} className="flex items-center gap-2 text-gray-600 hover:text-gray-900 mb-6"><ArrowLeft className="w-5 h-5" /> Back to Products</button>

      {(location.state as { success?: string } | null)?.success && <div className="mb-6 px-4 py-3 bg-emerald-50 border border-emerald-200 rounded-xl text-emerald-700">{(location.state as { success?: string }).success}</div>}

      {error && <div className="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl"><p className="font-semibold text-red-800">{error.status === 409 ? 'Product could not be deleted' : 'Product request failed'}</p><p className="text-sm text-red-700 mt-1">{error.message}</p></div>}

      <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden mb-6">
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-8 p-8">
          <div>{image ? <img src={image} alt={product.name} className="w-full aspect-square rounded-2xl object-cover bg-gray-100" /> : <div className="w-full aspect-square rounded-2xl bg-gray-100 flex flex-col items-center justify-center text-gray-400"><ImageIcon className="w-20 h-20 mb-3" /><span>No product image</span></div>}</div>
          <div className="flex flex-col">
            <div className="flex items-start justify-between gap-4 mb-4"><div><h1 className="text-3xl font-bold text-gray-900 mb-2">{product.name}</h1><p className="font-mono text-sm text-gray-500">{product.barcode}</p></div>{product.is_controlled && <span className="inline-flex items-center gap-2 px-3 py-2 bg-amber-50 border border-amber-200 text-amber-700 rounded-xl text-sm font-semibold"><ShieldAlert className="w-4 h-4" /> Controlled</span>}</div>
            <div className="border-t border-gray-100 pt-5 mb-6"><h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-2">Notes</h2><p className="text-gray-700 leading-relaxed whitespace-pre-wrap">{product.nots || 'No notes have been recorded for this product.'}</p></div>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
              <InfoCard label="Medicine type" value={product.is_controlled ? 'Controlled medicine' : 'Standard medicine'} />
              <InfoCard label="Control schedule" value={product.is_controlled ? product.control_schedule || 'Not specified' : 'Not applicable'} />
              <InfoCard label="Created" value={formatDate(product.created_at)} />
              <InfoCard label="Last updated" value={formatDate(product.updated_at)} />
            </div>
            <div className="flex gap-3 mt-auto"><PermissionGate permission="products.manage"><button onClick={() => navigate(`/products/${product.id}/edit`)} className="flex items-center gap-2 px-5 py-3 bg-gradient-to-r from-teal-600 to-emerald-500 text-white rounded-xl"><Edit className="w-4 h-4" /> Edit Product</button><button onClick={() => setConfirmingDelete(true)} className="flex items-center gap-2 px-5 py-3 bg-red-50 text-red-600 rounded-xl"><Trash2 className="w-4 h-4" /> Delete</button></PermissionGate></div>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-6">
        <div className="bg-white rounded-2xl border border-gray-100 p-6">
          <div className="flex items-center gap-3 mb-5"><div className="w-12 h-12 bg-teal-50 rounded-xl flex items-center justify-center"><Package className="w-7 h-7 text-teal-600" /></div><div><h2 className="text-lg font-bold text-gray-900">Batch inventory</h2><p className="text-sm text-gray-500">Persisted inventory records; FEFO is enforced by the backend sales workflow.</p></div></div>
          {!hasPermission('batches.manage') && <p className="p-4 bg-amber-50 text-amber-800 rounded-xl">You do not have permission to view batch inventory.</p>}
          {hasPermission('batches.manage') && batchesLoading && <p className="p-6 text-center text-gray-500">Loading batch inventory…</p>}
          {hasPermission('batches.manage') && batchesError && <p className="p-4 bg-red-50 text-red-700 rounded-xl">{batchesError.message}</p>}
          {hasPermission('batches.manage') && !batchesLoading && !batchesError && <>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5"><InfoCard label="Total current quantity" value={String(totalQuantity)} /><InfoCard label="Active batches" value={String(activeBatches.length)} /><InfoCard label="Nearest active expiry" value={nearestExpiry ? formatDateOnly(nearestExpiry) : 'None'} /></div>
            {batches.length === 0 ? <p className="p-6 text-center text-gray-500 bg-gray-50 rounded-xl">No batches have been created for this product.</p> : <div className="overflow-x-auto"><table className="w-full min-w-[720px]"><thead className="bg-gray-50 text-left text-xs uppercase text-gray-500"><tr><th className="p-3">Batch</th><th className="p-3">Expiry</th><th className="p-3 text-right">Quantity</th><th className="p-3 text-right">Purchase cost</th><th className="p-3 text-right">Sale price</th><th className="p-3">Status</th></tr></thead><tbody className="divide-y">{batches.map((batch) => <tr key={batch.id}><td className="p-3 font-mono text-sm">{batch.batch_number}</td><td className="p-3">{formatDateOnly(batch.expiry_date)}</td><td className="p-3 text-right font-semibold">{batch.quantity}</td><td className="p-3 text-right">{formatMoney(batch.purchase_price)}</td><td className="p-3 text-right">{formatMoney(batch.sale_price)}</td><td className="p-3"><StatusBadge status={batchStatus(batch)} /></td></tr>)}</tbody></table></div>}
          </>}
        </div>
        <ProductReporting productId={product.id} />
      </div>

      {confirmingDelete && <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" role="dialog" aria-modal="true"><div className="bg-white rounded-2xl shadow-xl max-w-md w-full p-6"><h2 className="text-xl font-bold text-gray-900 mb-2">Delete product?</h2><p className="text-gray-600 mb-6">Delete <strong>{product.name}</strong>? This action may be rejected if the product conflicts with existing records.</p><div className="flex gap-3"><button disabled={isDeleting} onClick={() => setConfirmingDelete(false)} className="flex-1 py-2.5 bg-gray-100 rounded-xl">Cancel</button><button disabled={isDeleting} onClick={() => void deleteProduct()} className="flex-1 py-2.5 bg-red-600 text-white rounded-xl disabled:opacity-60">{isDeleting ? 'Deleting...' : 'Delete'}</button></div></div></div>}
    </div>
  );
}

function InfoCard({ label, value }: { label: string; value: string }) {
  return <div className="p-4 bg-gray-50 rounded-xl"><p className="text-xs text-gray-500 mb-1">{label}</p><p className="font-semibold text-gray-900">{value}</p></div>;
}

function DetailLoading() {
  return <div className="p-8 animate-pulse"><div className="h-6 w-36 bg-gray-200 rounded mb-6" /><div className="bg-white rounded-2xl border border-gray-100 p-8 grid grid-cols-1 lg:grid-cols-2 gap-8"><div className="aspect-square bg-gray-200 rounded-2xl" /><div className="space-y-5"><div className="h-9 bg-gray-200 rounded w-2/3" /><div className="h-5 bg-gray-100 rounded w-1/2" /><div className="h-24 bg-gray-100 rounded" /><div className="grid grid-cols-2 gap-4"><div className="h-20 bg-gray-100 rounded" /><div className="h-20 bg-gray-100 rounded" /></div></div></div></div>;
}

function formatDate(value: string): string {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? 'Unavailable' : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(date);
}
