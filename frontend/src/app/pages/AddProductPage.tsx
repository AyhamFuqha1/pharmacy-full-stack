import { useEffect, useRef, useState, type ReactNode } from "react";
import { useForm } from "react-hook-form";
import { useNavigate, useParams } from "react-router";
import { useTranslation } from "react-i18next";
import {
  ArrowLeft,
  Image as ImageIcon,
  ShieldAlert,
  Upload,
} from "lucide-react";
import { ApiClientError } from "../../api/client";
import { productImageUrl, productsApi } from "../../api/products";
import { useAuth } from "../../auth/useAuth";
import type { ProductFormPayload } from "../../types/product";
import i18n from "../../i18n";

interface ProductFormValues {
  name: string;
  barcode: string;
  nots: string;
  is_controlled: boolean;
  control_schedule: string;
  reorder_level: number;
}

const acceptedImageTypes = ["image/jpeg", "image/png", "image/webp"];
const maxImageSize = 2 * 1024 * 1024;

export function AddProductPage() {
  const { t } = useTranslation();
  const { id } = useParams();
  const navigate = useNavigate();
  const { hasPermission } = useAuth();
  const isEditMode = id !== undefined;
  const productId = Number(id);
  const fileInput = useRef<HTMLInputElement>(null);
  const [imageFile, setImageFile] = useState<File | null>(null);
  const [imagePreview, setImagePreview] = useState<string | null>(null);
  const [currentImage, setCurrentImage] = useState<string | null>(null);
  const [imageError, setImageError] = useState<string | null>(null);
  const [apiError, setApiError] = useState<ApiClientError | null>(null);
  const [isLoadingProduct, setIsLoadingProduct] = useState(isEditMode);
  const [loadError, setLoadError] = useState<ApiClientError | null>(null);
  const {
    register,
    handleSubmit,
    reset,
    watch,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<ProductFormValues>({
    defaultValues: {
      name: "",
      barcode: "",
      nots: "",
      is_controlled: false,
      control_schedule: "",
      reorder_level: 0,
    },
  });
  const isControlled = watch("is_controlled");

  useEffect(() => {
    if (!isEditMode) return;
    if (!Number.isInteger(productId) || productId <= 0) {
      setLoadError(new ApiClientError(404, i18n.t("Product not found.")));
      setIsLoadingProduct(false);
      return;
    }

    const controller = new AbortController();
    productsApi
      .detail(productId, controller.signal)
      .then((response) => {
        const product = response.data;
        reset({
          name: product.name,
          barcode: product.barcode,
          nots: product.nots || "",
          is_controlled: product.is_controlled,
          control_schedule: product.control_schedule || "",
          reorder_level: Number(product.reorder_level ?? 0),
        });
        setCurrentImage(productImageUrl(product.img));
      })
      .catch((requestError: unknown) => {
        if (
          requestError instanceof DOMException &&
          requestError.name === "AbortError"
        )
          return;
        setLoadError(
          requestError instanceof ApiClientError
            ? requestError
            : new ApiClientError(0, i18n.t("Unable to load the product.")),
        );
      })
      .finally(() => {
        if (!controller.signal.aborted) setIsLoadingProduct(false);
      });
    return () => controller.abort();
  }, [isEditMode, productId, reset]);

  useEffect(() => {
    if (!imageFile) {
      setImagePreview(null);
      return;
    }
    const preview = URL.createObjectURL(imageFile);
    setImagePreview(preview);
    return () => URL.revokeObjectURL(preview);
  }, [imageFile]);

  function selectImage(file: File | undefined) {
    setImageError(null);
    if (!file) {
      setImageFile(null);
      return;
    }
    if (!acceptedImageTypes.includes(file.type)) {
      setImageError(t("Choose a JPG, PNG, or WebP image."));
      if (fileInput.current) fileInput.current.value = "";
      return;
    }
    if (file.size > maxImageSize) {
      setImageError(t("The product image must not exceed 2 MB."));
      if (fileInput.current) fileInput.current.value = "";
      return;
    }
    setImageFile(file);
  }

  function clearSelectedImage() {
    setImageFile(null);
    setImageError(null);
    if (fileInput.current) fileInput.current.value = "";
  }

  const onSubmit = handleSubmit(async (values) => {
    setApiError(null);
    const payload: ProductFormPayload = {
      ...values,
      nots: values.nots.trim(),
      control_schedule: values.is_controlled
        ? values.control_schedule.trim()
        : "",
      img: imageFile,
    };

    try {
      const response = isEditMode
        ? await productsApi.update(productId, payload)
        : await productsApi.create(payload);
      navigate(`/products/${response.data.id}`, {
        replace: true,
        state: {
          successKey: isEditMode
            ? "Product updated successfully."
            : "Product created successfully.",
        },
      });
    } catch (requestError) {
      if (requestError instanceof ApiClientError) {
        setApiError(requestError);
        Object.entries(requestError.errors).forEach(([field, messages]) => {
          if (field === "img") setImageError(messages[0]);
          if (
            field === "name" ||
            field === "barcode" ||
            field === "nots" ||
            field === "is_controlled" ||
            field === "control_schedule" ||
            field === "reorder_level"
          ) {
            setError(field, { message: messages[0] });
          }
        });
      } else {
        setApiError(new ApiClientError(0, t("Unable to save the product.")));
      }
    }
  });

  if (!hasPermission("products.manage")) {
    return (
      <div className="p-8">
        <button
          onClick={() => navigate("/products")}
          className="flex items-center gap-2 text-gray-600 mb-6"
        >
          <ArrowLeft className="w-5 h-5 rtl:rotate-180" />{" "}
          {t("Back to Products")}
        </button>
        <div className="bg-white rounded-2xl border border-gray-100 p-12 text-center">
          <ShieldAlert className="w-14 h-14 text-amber-400 mx-auto mb-4" />
          <h1 className="text-2xl font-bold text-gray-900 mb-2">
            {t("Products access denied")}
          </h1>
          <p className="text-gray-500">
            {t("You do not have permission to manage products.")}
          </p>
        </div>
      </div>
    );
  }

  if (isLoadingProduct) return <FormLoading />;

  if (loadError) {
    return (
      <div className="p-8">
        <button
          onClick={() => navigate("/products")}
          className="flex items-center gap-2 text-gray-600 mb-6"
        >
          <ArrowLeft className="w-5 h-5 rtl:rotate-180" />{" "}
          {t("Back to Products")}
        </button>
        <div className="bg-white rounded-2xl border border-gray-100 p-12 text-center">
          <h1 className="text-2xl font-bold text-gray-900 mb-2">
            {loadError.status === 404
              ? t("Product not found")
              : t("Unable to load product")}
          </h1>
          <p className="text-gray-500">{loadError.message}</p>
        </div>
      </div>
    );
  }

  const displayedImage = imagePreview || currentImage;

  return (
    <div className="p-8">
      <button
        onClick={() =>
          navigate(isEditMode ? `/products/${productId}` : "/products")
        }
        className="flex items-center gap-2 text-gray-600 hover:text-gray-900 mb-6"
      >
        <ArrowLeft className="w-5 h-5 rtl:rotate-180" />{" "}
        {t(isEditMode ? "Back to Product" : "Back to Products")}
      </button>
      <div className="mb-8">
        <h1 className="text-3xl font-bold text-gray-900 mb-2">
          {t(isEditMode ? "Edit Product" : "Add New Product")}
        </h1>
        <p className="text-gray-500">
          {isEditMode
            ? t("Update persisted product information")
            : t("Create a new pharmacy product record")}
        </p>
      </div>

      {apiError && (
        <div
          className="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl"
          role="alert"
        >
          <p className="font-semibold text-red-800">
            {apiError.status === 422
              ? t("Please correct the product form")
              : apiError.status === 403
                ? t("Products access denied")
                : t("Unable to save product")}
          </p>
          <p className="text-sm text-red-700 mt-1">{apiError.message}</p>
        </div>
      )}

      <form onSubmit={onSubmit} noValidate>
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <div className="lg:col-span-2 space-y-6">
            <div className="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
              <h2 className="text-lg font-bold text-gray-900 mb-6">
                {t("Basic Information")}
              </h2>
              <div className="space-y-5">
                <Field label={t("Product Name")} error={errors.name?.message}>
                  <input
                    type="text"
                    placeholder={t("e.g., Paracetamol 500mg")}
                    className={inputClass(errors.name?.message)}
                    {...register("name", {
                      required: t("Product name is required."),
                      minLength: {
                        value: 3,
                        message: t(
                          "Product name must contain at least 3 characters.",
                        ),
                      },
                      maxLength: {
                        value: 255,
                        message: t(
                          "Product name must not exceed 255 characters.",
                        ),
                      },
                    })}
                  />
                </Field>
                <Field label={t("Barcode")} error={errors.barcode?.message}>
                  <input
                    type="text"
                    placeholder="1234567890123"
                    className={`${inputClass(errors.barcode?.message)} font-mono`}
                    {...register("barcode", {
                      required: t("Barcode is required."),
                      maxLength: {
                        value: 50,
                        message: t("Barcode must not exceed 50 characters."),
                      },
                    })}
                  />
                </Field>
                <Field
                  label={t("Reorder level")}
                  error={errors.reorder_level?.message}
                >
                  <input
                    type="number"
                    min="0"
                    step="0.001"
                    inputMode="decimal"
                    className={inputClass(errors.reorder_level?.message)}
                    {...register("reorder_level", {
                      required: t("Reorder level is required."),
                      valueAsNumber: true,
                      min: {
                        value: 0,
                        message: t("Reorder level must be zero or greater."),
                      },
                    })}
                  />
                </Field>
                <Field
                  label={t("Description / Notes")}
                  error={errors.nots?.message}
                  optional
                >
                  <textarea
                    rows={5}
                    placeholder={t("Enter product notes...")}
                    className={`${inputClass(errors.nots?.message)} resize-none`}
                    {...register("nots", {
                      maxLength: {
                        value: 1000,
                        message: t("Notes must not exceed 1000 characters."),
                      },
                    })}
                  />
                </Field>
              </div>
            </div>

            <div className="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
              <div className="flex items-start gap-4">
                <div className="w-12 h-12 bg-amber-50 rounded-xl flex items-center justify-center">
                  <ShieldAlert className="w-6 h-6 text-amber-600" />
                </div>
                <div className="flex-1">
                  <h2 className="text-lg font-bold text-gray-900 mb-1">
                    {t("Controlled Medicine")}
                  </h2>
                  <p className="text-sm text-gray-500 mb-5">
                    {t(
                      "Record whether this product is subject to a controlled-drug schedule.",
                    )}
                  </p>
                  <label className="inline-flex items-center gap-3 cursor-pointer mb-5">
                    <input
                      type="checkbox"
                      className="w-5 h-5 accent-teal-600"
                      {...register("is_controlled")}
                    />
                    <span className="font-medium text-gray-700">
                      {t("This is a controlled medicine")}
                    </span>
                  </label>
                  {isControlled && (
                    <Field
                      label={t("Control Schedule")}
                      error={errors.control_schedule?.message}
                    >
                      <input
                        type="text"
                        placeholder={t("e.g., Schedule II")}
                        className={inputClass(errors.control_schedule?.message)}
                        {...register("control_schedule", {
                          validate: (value) =>
                            !isControlled ||
                            value.trim().length > 0 ||
                            t(
                              "Control schedule is required for controlled medicines.",
                            ),
                          maxLength: {
                            value: 30,
                            message: t(
                              "Control schedule must not exceed 30 characters.",
                            ),
                          },
                        })}
                      />
                    </Field>
                  )}
                </div>
              </div>
            </div>

            <div className="bg-gray-50 border border-gray-200 rounded-2xl p-5">
              <p className="font-semibold text-gray-700 mb-1">
                {t("Inventory fields are managed separately")}
              </p>
              <p className="text-sm text-gray-500">
                {t("productInventoryHelp")}
              </p>
            </div>
          </div>

          <div className="space-y-6">
            <div className="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
              <h2 className="text-lg font-bold text-gray-900 mb-4">
                {t("Product Image")}
              </h2>
              {displayedImage ? (
                <div className="relative mb-4">
                  <img
                    src={displayedImage}
                    alt={t("Product preview")}
                    className="w-full aspect-square object-cover rounded-xl"
                  />
                  {imagePreview && (
                    <button
                      type="button"
                      onClick={clearSelectedImage}
                      className="absolute top-2 right-2 px-3 py-1.5 bg-red-500 text-white text-sm rounded-lg"
                    >
                      {t("Undo replacement")}
                    </button>
                  )}
                </div>
              ) : (
                <div className="border-2 border-dashed border-gray-300 rounded-xl p-8 text-center mb-4">
                  <ImageIcon className="w-12 h-12 text-gray-400 mx-auto mb-3" />
                  <p className="text-sm text-gray-600">
                    {t("No image selected")}
                  </p>
                </div>
              )}
              <label className="block">
                <input
                  ref={fileInput}
                  type="file"
                  accept="image/jpeg,image/png,image/webp"
                  onChange={(event) => selectImage(event.target.files?.[0])}
                  className="hidden"
                />
                <span className="flex items-center justify-center gap-2 px-4 py-3 bg-gray-100 text-gray-700 rounded-xl cursor-pointer">
                  <Upload className="w-4 h-4" />
                  {t(displayedImage ? "Replace Image" : "Upload Image")}
                </span>
              </label>
              <p className="text-xs text-gray-500 mt-3">
                {t("JPG, PNG, or WebP. Maximum 2 MB.")}
              </p>
              {imageError && (
                <p className="text-sm text-red-600 mt-2">{imageError}</p>
              )}
            </div>

            <div className="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
              <button
                type="submit"
                disabled={isSubmitting}
                className="w-full py-3 bg-gradient-to-r from-teal-600 to-emerald-500 text-white rounded-xl disabled:opacity-60 disabled:cursor-not-allowed font-semibold mb-3"
              >
                {isSubmitting
                  ? isEditMode
                    ? t("Saving changes...")
                    : t("Creating product...")
                  : isEditMode
                    ? t("Save Changes")
                    : t("Create Product")}
              </button>
              <button
                type="button"
                disabled={isSubmitting}
                onClick={() =>
                  navigate(isEditMode ? `/products/${productId}` : "/products")
                }
                className="w-full py-3 bg-gray-100 text-gray-700 rounded-xl"
              >
                {t("Cancel")}
              </button>
            </div>
          </div>
        </div>
      </form>
    </div>
  );
}

function Field({
  label,
  error,
  optional = false,
  children,
}: {
  label: string;
  error?: string;
  optional?: boolean;
  children: ReactNode;
}) {
  const { t } = useTranslation();
  return (
    <div>
      <label className="block text-sm font-medium text-gray-700 mb-2">
        {label} {!optional && <span className="text-red-500">*</span>}
        {optional && (
          <span className="text-gray-400 text-xs">{t("(optional)")}</span>
        )}
      </label>
      {children}
      {error && <p className="text-sm text-red-600 mt-1">{error}</p>}
    </div>
  );
}

function inputClass(error?: string) {
  return `w-full px-4 py-3 bg-gray-50 border rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500 ${error ? "border-red-300" : "border-gray-200"}`;
}

function FormLoading() {
  return (
    <div className="p-8 animate-pulse">
      <div className="h-6 w-36 bg-gray-200 rounded mb-6" />
      <div className="h-10 w-64 bg-gray-200 rounded mb-8" />
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="lg:col-span-2 h-96 bg-white border border-gray-100 rounded-2xl" />
        <div className="h-80 bg-white border border-gray-100 rounded-2xl" />
      </div>
    </div>
  );
}
