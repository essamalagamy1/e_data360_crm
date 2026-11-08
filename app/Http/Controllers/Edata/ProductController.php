<?php

namespace App\Http\Controllers\Edata;

use App\Models\ChartOfAccount;
use App\Models\InvoiceProduct;
use App\Models\ProductService;
use App\Models\ProductServiceCategory;
use App\Models\ProductServiceUnit;
use App\Support\Edata\Access;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends BaseController
{
    public function index(Request $request)
    {
        $me = $this->me();
        Access::need($me, 'manage product & service');
        $company = $this->companyId();

        $query = ProductService::where('created_by', $company);

        if ($request->filled('q')) {
            $q = '%' . $request->query('q') . '%';
            $query->where(fn ($w) => $w->where('name', 'like', $q)->orWhere('sku', 'like', $q));
        }
        if ($request->filled('category')) {
            $query->where('category_id', (int) $request->query('category'));
        }
        if ($request->filled('active')) {
            $query->where('is_active', $request->query('active') === '1' ? 1 : 0);
        }

        $products = $query->orderByDesc('is_active')->orderBy('name')->paginate($this->perPage())->withQueryString();

        $usage = InvoiceProduct::whereIn('product_id', $products->getCollection()->pluck('id'))
            ->selectRaw('product_id, COUNT(*) as total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

        return view('edata.products.index', [
            'products' => $products,
            'usage' => $usage,
            'categories' => ProductServiceCategory::where('created_by', $company)->orderBy('name')->pluck('name', 'id'),
            'company' => $company,
            'canCreate' => $me->can('create product & service'),
            'canEdit' => $me->can('edit product & service'),
            'canDelete' => $me->can('delete product & service'),
        ]);
    }

    public function create()
    {
        Access::need($this->me(), 'create product & service');

        return view('edata.products.form', $this->formData(new ProductService(['type' => 'service'])));
    }

    public function store(Request $request)
    {
        Access::need($this->me(), 'create product & service');
        $data = $this->validated($request);
        $company = $this->companyId();

        $product = new ProductService();
        $this->fill($product, $data);
        $product->created_by = $company;
        $product->sku = $data['sku'] ?: 'SRV-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
        $product->quantity = 0;
        $product->save();

        return $this->done('تمت إضافة ' . $product->name . '.', route('edata.products.index'));
    }

    public function edit(int $product)
    {
        Access::need($this->me(), 'edit product & service');

        return view('edata.products.form', $this->formData($this->reachable($product)));
    }

    public function update(Request $request, int $product)
    {
        Access::need($this->me(), 'edit product & service');
        $record = $this->reachable($product);
        $data = $this->validated($request, $record);

        $this->fill($record, $data);
        $record->sku = $data['sku'] ?: $record->sku;
        $record->save();

        return $this->done('تم حفظ ' . $record->name . '. الفواتير السابقة تحتفظ بأسعارها.', route('edata.products.index'));
    }

    public function toggle(int $product)
    {
        Access::need($this->me(), 'edit product & service');
        $record = $this->reachable($product);

        $record->is_active = !$record->is_active;
        $record->save();

        return $this->done($record->is_active ? 'تم تفعيل ' . $record->name . '.' : 'تم تعطيل ' . $record->name . '. لن يظهر في الفواتير الجديدة.');
    }

    public function destroy(int $product)
    {
        Access::need($this->me(), 'delete product & service');
        $record = $this->reachable($product);

        if (InvoiceProduct::where('product_id', $record->id)->exists()) {
            return $this->failed('هذا البند مستخدم في فواتير سابقة، لذلك لا يمكن حذفه. عطّله بدلاً من ذلك.');
        }

        $record->delete();

        return $this->done('تم الحذف.');
    }

    private function reachable(int $id): ProductService
    {
        return ProductService::where('created_by', $this->companyId())->findOrFail($id);
    }

    private function formData(ProductService $product): array
    {
        $company = $this->companyId();

        return [
            'product' => $product,
            'categories' => ProductServiceCategory::where('created_by', $company)->whereIn('type', ['product & service', 'product_service', 'Product & Service', '0'])->orderBy('name')->get(['id', 'name']),
            'allCategories' => ProductServiceCategory::where('created_by', $company)->orderBy('name')->get(['id', 'name', 'type']),
            'units' => ProductServiceUnit::where('created_by', $company)->orderBy('name')->get(['id', 'name']),
            'accounts' => ChartOfAccount::where('created_by', $company)->orderBy('code')->get(['id', 'code', 'name']),
        ];
    }

    private function validated(Request $request, ?ProductService $product = null): array
    {
        $company = $this->companyId();

        return $request->validate([
            'name' => 'required|string|max:191',
            'description' => 'nullable|string|max:3000',
            'category_id' => ['required', 'integer', Rule::exists('product_service_categories', 'id')->where('created_by', $company)],
            'unit_id' => ['required', 'integer', Rule::exists('product_service_units', 'id')->where('created_by', $company)],
            'type' => ['required', Rule::in(['service', 'product'])],
            'sale_price' => 'required|numeric|min:0',
            'sale_chartaccount_id' => ['required', 'integer', Rule::exists('chart_of_accounts', 'id')->where('created_by', $company)],
            'sku' => ['nullable', 'string', 'max:60', Rule::unique('product_services', 'sku')->where('created_by', $company)->ignore($product?->id)],
        ], [], [
            'name' => 'الاسم',
            'description' => 'الوصف',
            'category_id' => 'التصنيف',
            'unit_id' => 'الوحدة',
            'type' => 'النوع',
            'sale_price' => 'السعر الافتراضي',
            'sale_chartaccount_id' => 'حساب الإيراد',
            'sku' => 'الرمز',
        ]);
    }

    private function fill(ProductService $product, array $data): void
    {
        $product->name = $data['name'];
        $product->description = $data['description'] ?? null;
        $product->category_id = $data['category_id'];
        $product->unit_id = $data['unit_id'];
        $product->type = $data['type'];
        $product->sale_price = $data['sale_price'];
        $product->sale_chartaccount_id = $data['sale_chartaccount_id'];
        if (!$product->exists) {
            $product->is_active = true;
            $product->purchase_price = 0;
            $product->expense_chartaccount_id = 0;
        }
    }
}
