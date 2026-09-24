import { batchesApi } from "../api/batches";
import { productsApi } from "../api/products";
import { lookupsApi } from "../api/lookups";
import { patientsApi } from "../api/clinical";
import { usersApi } from "../api/admin";
import type { SelectOption } from "./components/UxPrimitives";

export const productEntityLoader = async (search: string, page: number, signal: AbortSignal) => {
  const response = await productsApi.list({ search, page, per_page: 25 }, signal);
  return { options: response.data.map((product): SelectOption => ({ id: product.id, label: `${product.name}${product.barcode ? ` — ${product.barcode}` : ""}` })), hasMore: page < response.meta.last_page };
};

export const controlledProductEntityLoader = async (search: string, page: number, signal: AbortSignal) => {
  const response = await productsApi.list({ search, page, per_page: 25, is_controlled: true }, signal);
  return { options: response.data.map((product): SelectOption => ({ id: product.id, label: `${product.name}${product.barcode ? ` — ${product.barcode}` : ""}` })), hasMore: page < response.meta.last_page };
};

export const patientEntityLoader = async (search: string, page: number, signal: AbortSignal) => {
  const response = await patientsApi.list({ search, page, per_page: 25 }, signal);
  return { options: response.data.filter((patient) => patient.is_active).map((patient): SelectOption => ({ id: patient.id, label: patient.full_name })), hasMore: page < response.meta.last_page };
};

export const userEntityLoader = async (search: string, page: number, signal: AbortSignal) => {
  const response = await usersApi.list({ search, page, per_page: 25 }, signal);
  return { options: response.data.map((user): SelectOption => ({ id: user.id, label: `${user.name}${user.email ? ` — ${user.email}` : ""}` })), hasMore: page < response.meta.last_page };
};

export const partyEntityLoader = (partyType: "customer" | "supplier") => async (search: string, page: number, signal: AbortSignal) => {
  const response = await lookupsApi.parties({ q: search, party_type: partyType, page, per_page: 25 }, signal);
  return { options: response.data.map((party): SelectOption => ({ id: party.id, label: `${party.display_name}${party.phone_reference ? ` — ${party.phone_reference}` : ""}` })), hasMore: page < response.meta.last_page };
};

export const batchEntityLoader = (productId?: number, branchId?: number) => async (search: string, page: number, signal: AbortSignal) => {
  if (!productId) return { options: [], hasMore: false };
  const response = await batchesApi.list({ search, product_id: productId, branch_id: branchId, page, per_page: 25 }, signal);
  return { options: response.data.map((batch): SelectOption => ({ id: batch.id, label: `${batch.batch_number}${batch.expiry_date ? ` — ${batch.expiry_date}` : ""}` })), hasMore: page < response.meta.last_page };
};
