import { useEffect, useMemo, useState } from "react";
import { useTranslation } from "react-i18next";
import { erpApi, type JsonRecord } from "../../api/erpOperations";
import { ErrorNotice, StatusBadge, normalizeError } from "./ErpPrimitives";

export function CompleteUnitsManager({ id }: { id: number }) {
  const { t } = useTranslation();
  const [rows, setRows] = useState<JsonRecord[]>([]),
    [edit, setEdit] = useState<JsonRecord | null>(null),
    [name, setName] = useState(""),
    [factor, setFactor] = useState(""),
    [price, setPrice] = useState(""),
    [isBase, setIsBase] = useState(false),
    [error, setError] = useState<any>(null),
    [notice, setNotice] = useState("");
  const load = () =>
    erpApi
      .productUnits(id)
      .then((response) => setRows(response.data))
      .catch((value) =>
        setError(
          normalizeError(value, t("The sale units could not be loaded.")),
        ),
      );
  useEffect(() => {
    void load();
  }, [id]);
  const base = useMemo(
      () => rows.find((row) => Boolean(row.is_base) && Boolean(row.is_active)),
      [rows],
    ),
    baseName = String(base?.name || t("base unit"));
  const clear = () => {
    setEdit(null);
    setName("");
    setFactor("");
    setPrice("");
    setIsBase(false);
  };
  async function save(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    setNotice("");
    try {
      const payload = {
        name: name.trim(),
        base_quantity: isBase ? 1 : Number(factor),
        sale_price: Number(price),
        is_base: isBase,
        is_purchase_unit: true,
        is_sale_unit: true,
      };
      edit
        ? await erpApi.updateUnit(id, Number(edit.id), payload)
        : await erpApi.addUnit(id, payload);
      setNotice(
        t(edit ? "Unit updated successfully." : "Unit added successfully."),
      );
      clear();
      await load();
    } catch (value) {
      setError(normalizeError(value, t("The sale unit could not be saved.")));
    }
  }
  return (
    <section className="space-y-5">
      <div>
        <h2 className="text-xl font-bold">{t("Units / Packaging")}</h2>
        <p className="text-sm text-gray-500">
          {t(
            "Define this product’s sale units directly. No separate unit setup is needed.",
          )}
        </p>
      </div>
      {notice && (
        <div className="p-3 rounded-xl bg-emerald-50 text-emerald-700">
          {notice}
        </div>
      )}
      <ErrorNotice error={error} />
      <form
        className="rounded-2xl border bg-white p-5 space-y-4"
        onSubmit={save}
      >
        <h3 className="font-bold">
          {t(edit ? "Edit Sale Unit" : "Add Sale Unit")}
        </h3>
        <label>
          <span className="block text-sm font-medium mb-2">
            {t("Unit Name")}
          </span>
          <input
            required
            maxLength={100}
            className="input"
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder={t("e.g. Tablet, Strip, Box")}
          />
        </label>
        {!edit && (
          <label className="flex gap-2 items-center">
            <input
              type="checkbox"
              checked={isBase}
              disabled={Boolean(base)}
              onChange={(e) => {
                setIsBase(e.target.checked);
                if (e.target.checked) setFactor("1");
              }}
            />{" "}
            {t("This is the base unit")}
          </label>
        )}
        {!isBase && !Boolean(edit?.is_base) && (
          <label>
            <span className="block text-sm font-medium mb-2">
              {t("unitConversionQuestion", {
                unit: name || t("package"),
                baseUnit: baseName,
              })}
            </span>
            <div className="flex items-center gap-2">
              <input
                required
                min="1.001"
                step="0.001"
                className="input max-w-48"
                type="number"
                value={factor}
                onChange={(e) => setFactor(e.target.value)}
              />
              <bdi>{baseName}</bdi>
            </div>
          </label>
        )}
        <label>
          <span className="block text-sm font-medium mb-2">
            {t("Sale Price")}
          </span>
          <input
            required
            min="0"
            step="0.01"
            className="input max-w-64"
            type="number"
            value={price}
            onChange={(e) => setPrice(e.target.value)}
          />
        </label>
        {!base && !isBase && !edit && (
          <p className="text-sm text-amber-700">
            {t("Create the base sale unit first. Its conversion is always 1.")}
          </p>
        )}
        <div className="flex gap-2">
          <button
            disabled={!base && !isBase && !edit}
            className="report-action primary"
          >
            {t(edit ? "Save Changes" : "Add Sale Unit")}
          </button>
          {edit && (
            <button type="button" className="report-action" onClick={clear}>
              {t("Cancel")}
            </button>
          )}
        </div>
      </form>
      <div className="overflow-x-auto rounded-2xl border bg-white">
        <table className="w-full min-w-[760px]">
          <thead className="text-start">
            <tr>
              <th>{t("Unit")}</th>
              <th>{t("Equivalent")}</th>
              <th>{t("Sale Price")}</th>
              <th>{t("Base")}</th>
              <th>{t("Status")}</th>
              <th>{t("Actions")}</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={Number(row.id)}>
                <td>
                  <bdi>{String(row.name)}</bdi>
                </td>
                <td>
                  <bdi>
                    {t("products.unitsConversion", {
                      quantity: 1,
                      unit: row.name,
                      baseQuantity: row.base_quantity,
                    })}
                  </bdi>
                </td>
                <td>
                  <bdi dir="ltr">
                    {Number(row.sale_price || 0).toFixed(2)} ILS
                  </bdi>
                </td>
                <td>{t(row.is_base ? "Yes" : "No")}</td>
                <td>
                  <StatusBadge value={row.is_active ? "active" : "disabled"} />
                </td>
                <td>
                  <div className="flex gap-2">
                    <button
                      className="report-action"
                      onClick={() => {
                        setEdit(row);
                        setName(String(row.name));
                        setFactor(String(row.base_quantity));
                        setPrice(String(row.sale_price ?? ""));
                        setIsBase(Boolean(row.is_base));
                      }}
                    >
                      {t("Edit")}
                    </button>
                    {Boolean(row.is_active) && !Boolean(row.is_base) && (
                      <button
                        className="report-action text-red-600"
                        onClick={async () => {
                          if (
                            confirm(
                              t("disableUnitQuestion", { name: row.name }),
                            )
                          )
                            try {
                              await erpApi.disableUnit(id, Number(row.id));
                              setNotice(t("Unit disabled successfully."));
                              await load();
                            } catch (value) {
                              setError(
                                normalizeError(
                                  value,
                                  t("The sale unit could not be disabled."),
                                ),
                              );
                            }
                        }}
                      >
                        {t("Disable")}
                      </button>
                    )}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {!rows.length && (
          <p className="p-6 text-center text-gray-500">
            {t(
              "This product has no sale units configured yet. Add its base sale unit before selling it.",
            )}
          </p>
        )}
      </div>
    </section>
  );
}
