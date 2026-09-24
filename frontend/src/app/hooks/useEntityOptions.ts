import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { branchesApi, usersApi } from "../../api/admin";
import { batchesApi } from "../../api/batches";
import { customersApi } from "../../api/customers";
import { productsApi } from "../../api/products";
import { providersApi } from "../../api/providers";
import type { SelectOption } from "../components/UxPrimitives";

export function useEntityOptions({
  loadBatches = true,
  loadProducts = true,
  loadCustomers = true,
  loadSuppliers = true,
  loadUsers = true,
}: {
  loadBatches?: boolean;
  loadProducts?: boolean;
  loadCustomers?: boolean;
  loadSuppliers?: boolean;
  loadUsers?: boolean;
} = {}) {
  const { t, i18n } = useTranslation();
  const [branches, setBranches] = useState<SelectOption[]>([]);
  const [products, setProducts] = useState<SelectOption[]>([]);
  const [batches, setBatches] = useState<SelectOption[]>([]);
  const [customers, setCustomers] = useState<SelectOption[]>([]);
  const [suppliers, setSuppliers] = useState<SelectOption[]>([]);
  const [users, setUsers] = useState<SelectOption[]>([]);

  useEffect(() => {
    const controller = new AbortController();
    Promise.allSettled([
      branchesApi.list(1, 100, controller.signal).then((response) =>
        setBranches(response.data.map((branch) => ({
          id: branch.id,
          label: `${branch.code} — ${branch.name}${branch.address ? ` — ${branch.address}` : ""}`,
        }))),
      ),
      loadProducts ? productsApi.list({ page: 1, per_page: 100 }, controller.signal).then((response) =>
        setProducts(response.data.map((product) => ({
          id: product.id,
          label: `${product.name}${product.barcode ? ` — ${product.barcode}` : ""}`,
        }))),
      ) : Promise.resolve(),
      loadBatches ? batchesApi.listAll({}, controller.signal).then((response) =>
        setBatches(response.map((batch) => ({
          id: batch.id,
          label: `${batch.batch_number} — ${t("Exp.")} ${batch.expiry_date}${batch.quantity !== undefined ? ` — ${t("availableQuantity", { quantity: batch.quantity })}` : ""}`,
        }))),
      ) : Promise.resolve(),
      loadCustomers ? customersApi.list({ page: 1, per_page: 100 }, controller.signal).then((response) =>
        setCustomers(response.data.map((customer) => ({
          id: customer.id,
          label: `${customer.name}${customer.phone ? ` — ${customer.phone}` : ""}`,
        }))),
      ) : Promise.resolve(),
      loadSuppliers ? providersApi.list({ page: 1, per_page: 100 }, controller.signal).then((response) =>
        setSuppliers(response.data.map((supplier) => ({
          id: supplier.id,
          label: `${supplier.name}${supplier.phone ? ` — ${supplier.phone}` : ""}`,
        }))),
      ) : Promise.resolve(),
      loadUsers ? usersApi.list(1, 100, controller.signal).then((response) =>
        setUsers(response.data.map((user) => ({
          id: user.id,
          label: `${user.name} — ${user.email}`,
        }))),
      ) : Promise.resolve(),
    ]);
    return () => controller.abort();
  }, [i18n.resolvedLanguage, loadBatches, loadCustomers, loadProducts, loadSuppliers, loadUsers, t]);

  return { branches, products, batches, customers, suppliers, users };
}
