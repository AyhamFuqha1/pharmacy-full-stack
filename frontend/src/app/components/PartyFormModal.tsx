import { useState } from "react";
import { useForm } from "react-hook-form";
import { X } from "lucide-react";
import { useTranslation } from "react-i18next";
import { ApiClientError } from "../../api/client";
import type { PartyPayload } from "../../types/party";

interface Props {
  kind: "Customer" | "Supplier";
  initial?: PartyPayload;
  onClose: () => void;
  onSubmit: (payload: PartyPayload) => Promise<void>;
}

export function PartyFormModal({ kind, initial, onClose, onSubmit }: Props) {
  const { t } = useTranslation();
  const [requestError, setRequestError] = useState("");
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<PartyPayload>({
    defaultValues: initial ?? { name: "", phone_num: "", city: "" },
  });

  async function submit(values: PartyPayload) {
    setRequestError("");
    try {
      const parsedCreditLimit = Number(values.credit_limit),
        creditLimit =
          values.credit_limit === null ||
          values.credit_limit === undefined ||
          Number.isNaN(parsedCreditLimit)
            ? null
            : parsedCreditLimit;
      await onSubmit({
        ...values,
        city: values.city?.trim() || null,
        ...(kind === "Customer"
          ? { credit_limit: creditLimit, block_on_limit: creditLimit !== null }
          : {}),
      });
    } catch (error) {
      if (error instanceof ApiClientError) {
        Object.entries(error.errors).forEach(([field, messages]) => {
          if (
            field === "name" ||
            field === "phone_num" ||
            field === "city" ||
            field === "credit_limit"
          )
            setError(field, { message: messages[0] });
        });
        setRequestError(error.message);
      } else setRequestError(t("Unable to save party."));
    }
  }

  return (
    <div
      className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4"
      role="dialog"
      aria-modal="true"
    >
      <form
        onSubmit={handleSubmit(submit)}
        className="bg-white rounded-2xl shadow-2xl max-w-xl w-full"
      >
        <div className="flex justify-between items-center p-6 border-b">
          <div>
            <h2 className="text-2xl font-bold">
              {initial
                ? t("editParty", { party: t(kind) })
                : t("addNewParty", { party: t(kind) })}
            </h2>
            <p className="text-sm text-gray-500">
              {t("Only fields stored by the backend are shown.")}
            </p>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="p-2 hover:bg-gray-100 rounded-xl"
          >
            <X className="w-6 h-6" />
          </button>
        </div>
        <div className="p-6 space-y-5">
          {requestError && (
            <div className="p-3 bg-red-50 border border-red-200 text-red-700 rounded-xl">
              {requestError}
            </div>
          )}
          <Field label={t("Name")} error={errors.name?.message}>
            <input
              {...register("name", {
                required: t("Name is required."),
                maxLength: {
                  value: 255,
                  message: t("Name cannot exceed 255 characters."),
                },
              })}
              className="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500"
            />
          </Field>
          <Field label={t("Phone number")} error={errors.phone_num?.message}>
            <input
              type="tel"
              {...register("phone_num", {
                required: t("Phone number is required."),
                maxLength: {
                  value: 20,
                  message: t("Phone number cannot exceed 20 characters."),
                },
              })}
              className="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500"
            />
          </Field>
          <Field label={t("City (optional)")} error={errors.city?.message}>
            <input
              {...register("city", {
                maxLength: {
                  value: 255,
                  message: t("City cannot exceed 255 characters."),
                },
              })}
              className="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500"
            />
          </Field>
          {kind === "Customer" && (
            <Field
              label={t("Credit Limit")}
              error={errors.credit_limit?.message}
            >
              <input
                type="number"
                min="0"
                step="0.01"
                {...register("credit_limit", {
                  min: {
                    value: 0,
                    message: t("Credit limit cannot be negative."),
                  },
                  valueAsNumber: true,
                })}
                className="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500"
              />
              <span className="block text-sm text-gray-500 mt-1">
                {t("creditLimitHelp")}
              </span>
            </Field>
          )}
        </div>
        <div className="flex gap-3 p-6 border-t">
          <button
            type="button"
            disabled={isSubmitting}
            onClick={onClose}
            className="flex-1 py-3 bg-gray-100 rounded-xl"
          >
            {t("Cancel")}
          </button>
          <button
            disabled={isSubmitting}
            className="flex-1 py-3 bg-gradient-to-r from-teal-600 to-emerald-500 text-white rounded-xl disabled:opacity-60"
          >
            {isSubmitting ? t("Saving…") : t("saveParty", { party: t(kind) })}
          </button>
        </div>
      </form>
    </div>
  );
}

function Field({
  label,
  error,
  children,
}: {
  label: string;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <label className="block">
      <span className="block text-sm font-medium text-gray-700 mb-2">
        {label}
      </span>
      {children}
      {error && (
        <span className="block text-sm text-red-600 mt-1">{error}</span>
      )}
    </label>
  );
}
