<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Store;
use Illuminate\Support\Facades\Auth;
use App\Helpers\ApiResponse;
use App\Helpers\ApiErrorHandler;
use App\Models\Product;
use Exception;

class OrderController extends Controller
{
    /**
     * Lấy danh sách tất cả các đơn hàng (có phân trang).
     */
    public function index()
    {
        try {
            $orders = Order::with(['user', 'store'])->paginate(10);
            return ApiResponse::paginate($orders, "Danh sách đơn hàng được lấy thành công.");
        } catch (Exception $e) {
            return ApiErrorHandler::serverError("Lỗi khi lấy danh sách đơn hàng.");
        }
    }

    /**
     * Tạo đơn hàng mới.
     */
    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'user_id' => 'required|exists:users,id',
                'store_id' => 'required|exists:stores,id',
                'total_price' => 'required|numeric|min:0',
                'status' => 'required|string',
                'order_code' => 'required|unique:orders,order_code',
            ]);

            $order = Order::create($validatedData);

            return ApiResponse::success($order, "Tạo đơn hàng thành công.", 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ApiErrorHandler::validationError($e->errors());
        } catch (\Exception $e) {
            return ApiErrorHandler::serverError("Lỗi khi tạo đơn hàng: " . $e->getMessage());
        }
    }


    /**
     * Lấy thông tin chi tiết của một đơn hàng.
     */
    public function show($id)
    {
        try {
            $order = Order::with(['user', 'store'])->find($id);

            if (!$order) {
                return ApiErrorHandler::notFound("Đơn hàng không tồn tại.");
            }

            return ApiResponse::success($order, "Lấy thông tin đơn hàng thành công.");
        } catch (Exception $e) {
            return ApiErrorHandler::serverError("Lỗi khi lấy thông tin đơn hàng.");
        }
    }

    /**
     * Cập nhật thông tin đơn hàng.
     */
    public function update(Request $request, $id)
    {
        try {
            $order = Order::find($id);

            if (!$order) {
                return ApiErrorHandler::notFound("Đơn hàng không tồn tại.");
            }

            $validatedData = $request->validate([
                'total_price' => 'numeric|min:0',
                'status' => 'string',
                'order_code' => "unique:orders,order_code,$id",
            ]);

            $order->update($validatedData);

            return ApiResponse::success($order, "Cập nhật đơn hàng thành công.");
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ApiErrorHandler::validationError($e->errors());
        } catch (Exception $e) {
            return ApiErrorHandler::serverError("Lỗi khi cập nhật đơn hàng.");
        }
    }

    /**
     * Xóa đơn hàng.
     */
    public function destroy($id)
    {
        try {
            $order = Order::find($id);

            if (!$order) {
                return ApiErrorHandler::notFound("Đơn hàng không tồn tại.");
            }

            $order->delete();

            return ApiResponse::success([], "Xóa đơn hàng thành công.");
        } catch (Exception $e) {
            return ApiErrorHandler::serverError("Lỗi khi xóa đơn hàng.");
        }
    }

    private $storeId;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $user = Auth::user();

            if ($user && $user->role === 3) {
                $store = Store::where('user_id', $user->id)->first();
                $this->storeId = $store ? $store->id : null;
            } else {
                $this->storeId = null;
            }

            return $next($request);
        })->except(['index', 'store', 'show', 'update', 'destroy', 'getUserOrders']);
    }

    public function getOrdersByStore(Request $request, $storeId)
    {
        if ($this->storeId === null) {
            $user = Auth::user();
            if ($user && $user->role === 3) {
                $store = Store::where('user_id', $user->id)->first();
                $this->storeId = $store ? $store->id : null;
            }
        }

        if (!$this->storeId || $this->storeId != $storeId) {
            return ApiResponse::error("Bạn không có quyền truy cập", [], 403);
        }

        $status = $request->query('status', 'pending');
        $search = $request->query('search', '');
        $perPage = $request->query('perPage', 10);

        $query = Order::where('store_id', $this->storeId)
            ->with(['user', 'orderItems.product.images'])
            ->when($status, function ($query) use ($status) {
                if ($status === 'deleted') {
                    return $query->onlyTrashed();
                }
                return $query->where('status', $status);
            })
            ->when($search, function ($query) use ($search) {
                return $query->where(function ($q) use ($search) {
                    $q->where('order_code', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($q) use ($search) {
                            $q->where('username', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->latest();

        $orders = $query->paginate($perPage);

        return ApiResponse::success([
            'data' => $orders->map(fn($order) => $this->formatOrder($order)),
            'pagination' => [
                'total' => $orders->total(),
                'per_page' => $orders->perPage(),
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
            ],
        ], "Lấy danh sách đơn hàng thành công");
    }

    public function getOrderDetail($storeId, $orderId)
    {
        if (!$this->storeId || $this->storeId != $storeId) {
            return ApiResponse::error("Bạn không có quyền truy cập", [], 403);
        }

        $order = Order::where('id', $orderId)->where('store_id', $this->storeId)->first();

        if (!$order) {
            return ApiResponse::error("Đơn hàng không tồn tại", [], 404);
        }

        return ApiResponse::success($this->formatOrder($order), "Lấy chi tiết đơn hàng thành công");
    }

    public function updateOrderStatus(Request $request, $storeId, $orderId)
    {
        if (!$this->storeId || $this->storeId != $storeId) {
            return ApiResponse::error("Bạn không có quyền truy cập", [], 403);
        }

        $order = Order::where('id', $orderId)->where('store_id', $this->storeId)->first();

        if (!$order) {
            return ApiResponse::error("Đơn hàng không tồn tại", [], 404);
        }

        $request->validate([
            'status' => 'required|in:pending,completed'
        ]);

        $order->status = $request->status;
        $order->save();

        return ApiResponse::success($this->formatOrder($order), "Cập nhật trạng thái đơn hàng thành công");
    }

    public function deleteOrder($storeId, $orderId)
    {
        if (!$this->storeId || $this->storeId != $storeId) {
            return ApiResponse::error("Bạn không có quyền truy cập", [], 403);
        }

        $order = Order::where('id', $orderId)->where('store_id', $this->storeId)->first();

        if (!$order) {
            return ApiResponse::error("Đơn hàng không tồn tại", [], 404);
        }

        $order->delete();

        return ApiResponse::success(null, "Xóa đơn hàng thành công");
    }

    public function forceDeleteOrder($storeId, $orderId)
    {
        if (!$this->storeId || $this->storeId != $storeId) {
            return ApiResponse::error("Bạn không có quyền truy cập", [], 403);
        }

        $order = Order::onlyTrashed()->where('id', $orderId)->where('store_id', $this->storeId)->first();

        if (!$order) {
            return ApiResponse::error("Đơn hàng không tồn tại hoặc chưa bị xóa tạm thời", [], 404);
        }

        $order->forceDelete();

        return ApiResponse::success(null, "Xóa vĩnh viễn đơn hàng thành công");
    }

    public function restoreOrder($storeId, $orderId)
    {
        if (!$this->storeId || $this->storeId != $storeId) {
            return ApiResponse::error("Bạn không có quyền truy cập", [], 403);
        }

        $order = Order::onlyTrashed()->where('id', $orderId)->where('store_id', $this->storeId)->first();

        if (!$order) {
            return ApiResponse::error("Đơn hàng không tồn tại hoặc chưa bị xóa tạm thời", [], 404);
        }

        $order->restore();

        return ApiResponse::success($this->formatOrder($order), "Khôi phục đơn hàng thành công");
    }

    private function formatOrder($order)
    {
        // Kiểm tra nếu order tồn tại
        if (!$order) {
            return null;
        }

        $firstItem = $order->orderItems->isNotEmpty() ? $order->orderItems->first() : null;
        $firstProduct = $firstItem && $firstItem->product ? $firstItem->product : null;
        $firstImage = $firstProduct && $firstProduct->images && $firstProduct->images->isNotEmpty()
            ? $firstProduct->images->first()->image_url
            : null;

        // Kiểm tra user tồn tại
        $userData = [
            'id' => null,
            'username' => 'N/A',
            'email' => 'N/A',
            'phone' => 'N/A',
        ];

        if ($order->user) {
            $userData = [
                'id' => $order->user->id,
                'username' => $order->user->username ?? 'N/A',
                'email' => $order->user->email ?? 'N/A',
                'phone' => $order->user->phone_number ?? 'N/A',
            ];
        }

        return [
            'id' => $order->id,
            'order_code' => $order->order_code,
            'user' => $userData,
            'total_price' => $order->total_price,
            'status' => $order->status,
            'order_date' => $order->created_at,
            'main_product' => [
                'name' => $firstProduct ? $firstProduct->name : 'N/A',
                'image' => $firstImage,
                'quantity' => $firstItem ? $firstItem->quantity : 0,
            ],
            'total_items' => $order->orderItems->count(),
            'items' => $order->orderItems->map(function ($item) {
                // Kiểm tra product tồn tại
                if (!$item || !$item->product) {
                    return [
                        'id' => $item ? $item->id : null,
                        'product' => [
                            'id' => null,
                            'name' => 'N/A',
                            'price' => 0,
                            'image' => null,
                        ],
                        'quantity' => $item ? $item->quantity : 0,
                    ];
                }

                return [
                    'id' => $item->id,
                    'product' => [
                        'id' => $item->product->id,
                        'name' => $item->product->name ?? 'N/A',
                        'price' => $item->price,
                        'image' => $item->product && $item->product->images && $item->product->images->isNotEmpty()
                            ? $item->product->images->first()->image_url
                            : null,
                    ],
                    'quantity' => $item->quantity,
                ];
            }),
        ];
    }

    public function getUserOrders()
    {
        $user = Auth::user(); // Lấy user hiện tại
        if (!$user) {
            return ApiResponse::error(null, "Unauthorized", 401);
        }

        $orders = Order::where('user_id', $user->id)
            ->with(['store', 'orderItems.product'])
            ->get()
            ->groupBy('store_id');

        $formattedOrders = $orders->map(function ($ordersByStore) {
            $store = $ordersByStore->first()->store;
            return [
                'store_id' => $store->id,
                'store_name' => $store->store_name,
                'store_latitude' => $store->latitude,
                'store_longitude' => $store->longitude,
                'orders' => $ordersByStore->map(function ($order) {
                    return [
                        'order_id' => $order->id,
                        'order_code' => $order->order_code,
                        'total_price' => $order->total_price,
                        'status' => $order->status,
                        'items' => $order->orderItems->map(function ($item) {
                            $originalTotal = $item->product->original_price * $item->quantity;
                            $discountedTotal = $item->product->discounted_price * $item->quantity;
                            $savedAmount = $originalTotal - $discountedTotal;

                            return [
                                'product_id' => $item->product->id,
                                'product_name' => $item->product->name,
                                'product_image' => $item->product->images->pluck('image_url'),
                                'quantity' => $item->quantity,
                                'sub_price' => $item->price,
                                'unique_price' => $item->product->discounted_price,
                                'original_price' => $item->product->original_price,
                                'saved_amount' => $savedAmount, // Số tiền tiết kiệm được
                            ];
                        }),
                        'order_date' => $order->order_date
                    ];
                }),
            ];
        });

        return ApiResponse::success($formattedOrders, "Orders fetched successfully");
    }
}
