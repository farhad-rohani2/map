<?php
// index.php - سرور API کامل با قابلیت‌های پیشرفته

$dataFile = __DIR__ . '/data.json';

// تابع جستجوی مختصات با Nominatim
function geocodeAddress($address, $city = '', $state = '', $country = 'Iran') {
    $query = trim($address . ' ' . $city . ' ' . $state . ' ' . $country);
    if (empty($query)) return null;

    $url = 'https://nominatim.openstreetmap.org/search?q=' . urlencode($query) .
        '&format=json&limit=1&addressdetails=1&countrycodes=ir&extratags=1&namedetails=1';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'SchoolMapApp/1.0');
    curl_setopt($ch, CURLOPT_REFERER, 'http://localhost');
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $error) return null;
    $data = json_decode($response, true);
    if (!empty($data) && isset($data[0]['lat'], $data[0]['lon'])) {
        return [
            'lat' => floatval($data[0]['lat']),
            'lng' => floatval($data[0]['lon']),
            'display_name' => $data[0]['display_name'] ?? '',
            'class' => $data[0]['class'] ?? '',
            'type' => $data[0]['type'] ?? ''
        ];
    }
    return null;
}

// =============================================================
// اکشن ۱: دریافت آمار کلی
// =============================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_stats') {
    header('Content-Type: application/json; charset=utf-8');

    if (!file_exists($dataFile)) {
        echo json_encode(['error' => 'فایل data.json یافت نشد']);
        exit;
    }

    $json = file_get_contents($dataFile);
    $schools = json_decode($json, true);
    if (!is_array($schools)) {
        echo json_encode(['error' => 'فرمت داده نامعتبر']);
        exit;
    }

    $total = count($schools);
    $withCoords = 0;
    $withoutCoords = 0;
    $geocoded = 0;
    $verified = 0;

    foreach ($schools as $s) {
        $hasCoords = isset($s['lat']) && isset($s['lng']) && is_numeric($s['lat']) && is_numeric($s['lng']) && $s['lat'] != 0 && $s['lng'] != 0;
        if ($hasCoords) {
            $withCoords++;
            if (isset($s['_geocoded']) && $s['_geocoded'] === true) {
                $geocoded++;
            }
            if (isset($s['_coords_verified']) && $s['_coords_verified'] === true) {
                $verified++;
            }
        } else {
            $withoutCoords++;
        }
    }

    echo json_encode([
        'total' => $total,
        'with_coords' => $withCoords,
        'without_coords' => $withoutCoords,
        'geocoded' => $geocoded,
        'verified' => $verified
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// =============================================================
// اکشن ۲: دریافت فیلدهای فیلتر
// =============================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_filter_fields') {
    header('Content-Type: application/json; charset=utf-8');

    if (!file_exists($dataFile)) {
        echo json_encode(['error' => 'فایل data.json یافت نشد']);
        exit;
    }

    $json = file_get_contents($dataFile);
    $schools = json_decode($json, true);
    if (!is_array($schools)) {
        echo json_encode(['error' => 'فرمت داده نامعتبر']);
        exit;
    }

    $fields = [
        'school_type' => [],
        'sex' => [],
        'section' => [],
        'city' => [],
        'dorm' => ['true', 'false'],
        'library' => ['true', 'false'],
        'lab' => ['true', 'false'],
        'smart_class' => ['true', 'false']
    ];

    foreach ($schools as $s) {
        foreach ($fields as $key => &$values) {
            if (isset($s[$key]) && !empty($s[$key])) {
                $val = is_bool($s[$key]) ? ($s[$key] ? 'true' : 'false') : $s[$key];
                if (!in_array($val, $values)) {
                    $values[] = $val;
                }
            }
        }
    }

    foreach ($fields as &$values) {
        sort($values);
    }

    echo json_encode($fields, JSON_UNESCAPED_UNICODE);
    exit;
}

// =============================================================
// اکشن ۳: دریافت مدارس با فیلتر (محدوده، جستجو، فیلترهای پیشرفته)
// =============================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_schools') {
    header('Content-Type: application/json; charset=utf-8');

    if (!file_exists($dataFile)) {
        echo json_encode(['error' => 'فایل data.json یافت نشد']);
        exit;
    }

    $json = file_get_contents($dataFile);
    $schools = json_decode($json, true);
    if (!is_array($schools)) {
        echo json_encode(['error' => 'فرمت داده نامعتبر']);
        exit;
    }

    // پارامترهای فیلتر
    $north = isset($_GET['north']) ? floatval($_GET['north']) : null;
    $south = isset($_GET['south']) ? floatval($_GET['south']) : null;
    $east  = isset($_GET['east'])  ? floatval($_GET['east'])  : null;
    $west  = isset($_GET['west'])  ? floatval($_GET['west'])  : null;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $filters = isset($_GET['filters']) ? json_decode($_GET['filters'], true) : [];

    // فیلترهای پیشرفته
    $school_type = $filters['school_type'] ?? '';
    $sex = $filters['sex'] ?? '';
    $section = $filters['section'] ?? '';
    $city = $filters['city'] ?? '';
    $min_score = isset($filters['min_score']) ? floatval($filters['min_score']) : 0;
    $max_score = isset($filters['max_score']) ? floatval($filters['max_score']) : 999999;
    $has_dorm = isset($filters['dorm']) ? ($filters['dorm'] === 'true') : null;
    $has_library = isset($filters['library']) ? ($filters['library'] === 'true') : null;
    $has_lab = isset($filters['lab']) ? ($filters['lab'] === 'true') : null;
    $has_smart = isset($filters['smart_class']) ? ($filters['smart_class'] === 'true') : null;

    $filtered = [];
    foreach ($schools as $s) {
        // تعیین مختصات نهایی: اول lat/lng اصلی، سپس _geocoded_lat/lng
        $lat = null;
        $lng = null;
        if (isset($s['lat']) && is_numeric($s['lat']) && $s['lat'] != 0 &&
            isset($s['lng']) && is_numeric($s['lng']) && $s['lng'] != 0) {
            $lat = floatval($s['lat']);
            $lng = floatval($s['lng']);
        } elseif (isset($s['_geocoded_lat']) && isset($s['_geocoded_lng'])) {
            $lat = floatval($s['_geocoded_lat']);
            $lng = floatval($s['_geocoded_lng']);
        }

        if ($lat === null || $lng === null) continue;

        // فیلتر محدوده
        if ($north !== null && $lat > $north) continue;
        if ($south !== null && $lat < $south) continue;
        if ($east  !== null && $lng > $east)  continue;
        if ($west  !== null && $lng < $west)  continue;

        // فیلتر جستجو
        if (!empty($search)) {
            $text = json_encode($s, JSON_UNESCAPED_UNICODE);
            if (stripos($text, $search) === false) continue;
        }

        // فیلترهای پیشرفته
        if (!empty($school_type)) {
            $st = $s['school_type'] ?? $s['school_type_title'] ?? '';
            if ($st !== $school_type) continue;
        }
        if (!empty($sex)) {
            $sx = $s['sex'] ?? $s['sex_title'] ?? '';
            if ($sx !== $sex) continue;
        }
        if (!empty($section)) {
            $sec = $s['section'] ?? $s['section_title'] ?? '';
            if ($sec !== $section) continue;
        }
        if (!empty($city)) {
            $ct = $s['city'] ?? $s['city_title'] ?? '';
            if ($ct !== $city) continue;
        }
        if (isset($s['score']) && is_numeric($s['score'])) {
            $score = floatval($s['score']);
            if ($score < $min_score || $score > $max_score) continue;
        }
        if ($has_dorm !== null) {
            $dorm = isset($s['dorm']) ? (bool)$s['dorm'] : false;
            if ($dorm !== $has_dorm) continue;
        }
        if ($has_library !== null) {
            $lib = isset($s['library']) ? (bool)$s['library'] : false;
            if ($lib !== $has_library) continue;
        }
        if ($has_lab !== null) {
            $lab = isset($s['lab']) ? (bool)$s['lab'] : false;
            if ($lab !== $has_lab) continue;
        }
        if ($has_smart !== null) {
            $smart = isset($s['smart_class']) ? (bool)$s['smart_class'] : false;
            if ($smart !== $has_smart) continue;
        }

        // تنظیم مختصات نهایی برای نمایش
        $s['lat'] = $lat;
        $s['lng'] = $lng;
        $filtered[] = $s;
    }

    echo json_encode(array_values($filtered), JSON_UNESCAPED_UNICODE);
    exit;
}

// =============================================================
// اکشن ۴: تبدیل آدرس یک مدرسه (تکی)
// =============================================================
if (isset($_GET['action']) && $_GET['action'] === 'geocode_single' && isset($_GET['id'])) {
    header('Content-Type: application/json; charset=utf-8');

    $id = $_GET['id'];

    if (!file_exists($dataFile)) {
        echo json_encode(['error' => 'فایل data.json یافت نشد']);
        exit;
    }

    $json = file_get_contents($dataFile);
    $schools = json_decode($json, true);
    if (!is_array($schools)) {
        echo json_encode(['error' => 'فرمت داده نامعتبر']);
        exit;
    }

    $found = null;
    foreach ($schools as &$s) {
        if (isset($s['id']) && $s['id'] == $id) {
            if (empty($s['address'])) {
                echo json_encode(['error' => 'آدرسی برای این مدرسه ثبت نشده است']);
                exit;
            }

            $coords = geocodeAddress($s['address'], $s['city'] ?? '', $s['state'] ?? '');
            if ($coords) {
                $s['_geocoded_lat'] = $coords['lat'];
                $s['_geocoded_lng'] = $coords['lng'];
                $s['_geocode_info'] = $coords;
                $s['_geocode_status'] = 'pending';
                $s['_geocoded'] = true;
                $s['lat'] = $coords['lat'];
                $s['lng'] = $coords['lng'];
                $found = $s;
            } else {
                echo json_encode(['error' => 'مختصاتی برای این آدرس پیدا نشد']);
                exit;
            }
            break;
        }
    }

    if ($found) {
        file_put_contents($dataFile, json_encode($schools, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        echo json_encode($found, JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['error' => 'مدرسه یافت نشد']);
    }
    exit;
}

// =============================================================
// اکشن ۵: تایید مختصات (POST)
// =============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_coords') {
    header('Content-Type: application/json; charset=utf-8');

    $id = $_POST['id'] ?? null;
    $lat = isset($_POST['lat']) ? floatval($_POST['lat']) : null;
    $lng = isset($_POST['lng']) ? floatval($_POST['lng']) : null;

    if (!$id || $lat === null || $lng === null) {
        echo json_encode(['success' => false, 'error' => 'اطلاعات کامل نیست']);
        exit;
    }

    if (!file_exists($dataFile)) {
        echo json_encode(['success' => false, 'error' => 'فایل data.json یافت نشد']);
        exit;
    }

    $json = file_get_contents($dataFile);
    $schools = json_decode($json, true);
    if (!is_array($schools)) {
        echo json_encode(['success' => false, 'error' => 'فرمت داده نامعتبر']);
        exit;
    }

    $found = false;
    foreach ($schools as &$s) {
        if (isset($s['id']) && $s['id'] == $id) {
            $s['lat'] = $lat;
            $s['lng'] = $lng;
            $s['_geocoded'] = false;
            $s['_coords_verified'] = true;
            $s['_geocode_status'] = 'verified';
            if (isset($s['_geocoded_lat'])) unset($s['_geocoded_lat']);
            if (isset($s['_geocoded_lng'])) unset($s['_geocoded_lng']);
            $found = true;
            break;
        }
    }

    if ($found) {
        $result = file_put_contents($dataFile, json_encode($schools, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        if ($result === false) {
            echo json_encode(['success' => false, 'error' => 'خطا در ذخیره فایل']);
        } else {
            echo json_encode(['success' => true]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'مدرسه یافت نشد']);
    }
    exit;
}

// =============================================================
// اکشن ۶: ذخیره تغییرات مدرسه (POST) - ویرایش شده برای پذیرش مختصات
// =============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_school') {
    header('Content-Type: application/json; charset=utf-8');

    $schoolData = json_decode($_POST['school'] ?? '', true);
    if (!$schoolData || !isset($schoolData['id'])) {
        echo json_encode(['success' => false, 'error' => 'داده نامعتبر']);
        exit;
    }

    if (!file_exists($dataFile)) {
        echo json_encode(['success' => false, 'error' => 'فایل data.json یافت نشد']);
        exit;
    }

    $json = file_get_contents($dataFile);
    $schools = json_decode($json, true);
    if (!is_array($schools)) {
        echo json_encode(['success' => false, 'error' => 'فرمت داده نامعتبر']);
        exit;
    }

    $found = false;
    foreach ($schools as &$s) {
        if (isset($s['id']) && $s['id'] == $schoolData['id']) {
            // فیلدهای مجاز برای ویرایش
            $allowed = ['notes', 'visit_date', 'contact_result', 'priority', 'follow_up', 'visited'];
            foreach ($allowed as $field) {
                if (array_key_exists($field, $schoolData)) {
                    $s[$field] = $schoolData[$field];
                }
            }

            // پردازش مختصات اگر ارسال شده باشند
            if (isset($schoolData['lat']) && isset($schoolData['lng'])) {
                $lat = floatval($schoolData['lat']);
                $lng = floatval($schoolData['lng']);
                if (!is_numeric($lat) || !is_numeric($lng) || $lat == 0 || $lng == 0) {
                    echo json_encode(['success' => false, 'error' => 'مختصات نامعتبر']);
                    exit;
                }
                $s['lat'] = $lat;
                $s['lng'] = $lng;
                $s['_coords_verified'] = true;
                $s['_geocoded'] = false;
                // حذف اطلاعات قبلی جیوکدینگ
                unset($s['_geocoded_lat'], $s['_geocoded_lng'], $s['_geocode_info']);
                $s['_geocode_status'] = 'verified';
            }

            $found = true;
            break;
        }
    }

    if (!$found) {
        echo json_encode(['success' => false, 'error' => 'مدرسه یافت نشد']);
        exit;
    }

    if (file_put_contents($dataFile, json_encode($schools, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'خطا در ذخیره فایل']);
    }
    exit;
}

// =============================================================
// اکشن ۷: تبدیل همه مدارس بدون مختصات (دسته‌جمعی)
// =============================================================
if (isset($_GET['action']) && $_GET['action'] === 'geocode_all') {
    header('Content-Type: application/json; charset=utf-8');

    if (!file_exists($dataFile)) {
        echo json_encode(['error' => 'فایل data.json یافت نشد']);
        exit;
    }

    $json = file_get_contents($dataFile);
    $schools = json_decode($json, true);
    if (!is_array($schools)) {
        echo json_encode(['error' => 'فرمت داده نامعتبر']);
        exit;
    }

    $total = count($schools);
    $success = 0;
    $failed = 0;
    $already_had_coords = 0;
    $errors = [];

    foreach ($schools as &$s) {
        // بررسی وجود مختصات معتبر
        $hasCoords = isset($s['lat']) && is_numeric($s['lat']) && $s['lat'] != 0 &&
            isset($s['lng']) && is_numeric($s['lng']) && $s['lng'] != 0;

        if ($hasCoords) {
            $already_had_coords++;
            continue;
        }

        if (empty($s['address'])) {
            $failed++;
            $errors[] = ['name' => $s['name'] ?? 'بدون نام', 'reason' => 'آدرس ندارد'];
            continue;
        }

        // اگر قبلاً مختصات پیدا شده، از همان استفاده کن
        if (isset($s['_geocoded_lat']) && isset($s['_geocoded_lng'])) {
            $s['lat'] = $s['_geocoded_lat'];
            $s['lng'] = $s['_geocoded_lng'];
            $s['_geocode_status'] = 'pending';
            $s['_geocoded'] = true;
            $success++;
            continue;
        }

        // درخواست به Nominatim با تاخیر ۱ ثانیه
        usleep(1000000);
        $coords = geocodeAddress($s['address'], $s['city'] ?? '', $s['state'] ?? '');
        if ($coords) {
            $s['lat'] = $coords['lat'];
            $s['lng'] = $coords['lng'];
            $s['_geocoded_lat'] = $coords['lat'];
            $s['_geocoded_lng'] = $coords['lng'];
            $s['_geocode_info'] = $coords;
            $s['_geocode_status'] = 'pending';
            $s['_geocoded'] = true;
            $success++;
        } else {
            $failed++;
            $errors[] = ['name' => $s['name'] ?? 'بدون نام', 'reason' => 'آدرس پیدا نشد'];
        }
    }

    // ذخیره فایل
    if (file_put_contents($dataFile, json_encode($schools, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
        echo json_encode(['error' => 'خطا در ذخیره فایل']);
        exit;
    }

    echo json_encode([
        'total' => $total,
        'success' => $success,
        'failed' => $failed,
        'already_had_coords' => $already_had_coords,
        'errors' => $errors
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// =============================================================
// اکشن ۸: ثبت مدرسه جدید (POST)
// =============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_school') {
    header('Content-Type: application/json; charset=utf-8');

    $schoolData = json_decode($_POST['school'] ?? '', true);
    if (!$schoolData) {
        echo json_encode(['success' => false, 'error' => 'داده نامعتبر']);
        exit;
    }

    // اعتبارسنجی حداقل فیلدها
    if (empty($schoolData['name'])) {
        echo json_encode(['success' => false, 'error' => 'نام مدرسه الزامی است']);
        exit;
    }

    if (!file_exists($dataFile)) {
        echo json_encode(['success' => false, 'error' => 'فایل data.json یافت نشد']);
        exit;
    }

    $json = file_get_contents($dataFile);
    $schools = json_decode($json, true);
    if (!is_array($schools)) {
        echo json_encode(['success' => false, 'error' => 'فرمت داده نامعتبر']);
        exit;
    }

    // تولید id جدید (بزرگترین id + 1)
    $maxId = 0;
    foreach ($schools as $s) {
        if (isset($s['id']) && $s['id'] > $maxId) {
            $maxId = $s['id'];
        }
    }
    $newId = $maxId + 1;

    // فیلدهای پیش‌فرض
    $newSchool = [
        'id' => $newId,
        'name' => $schoolData['name'] ?? '',
        'school_type' => $schoolData['school_type'] ?? '',
        'sex' => $schoolData['sex'] ?? '',
        'section' => $schoolData['section'] ?? '',
        'city' => $schoolData['city'] ?? '',
        'address' => $schoolData['address'] ?? '',
        'phone1' => $schoolData['phone1'] ?? '',
        'score' => isset($schoolData['score']) ? floatval($schoolData['score']) : 0,
        'lat' => isset($schoolData['lat']) ? floatval($schoolData['lat']) : null,
        'lng' => isset($schoolData['lng']) ? floatval($schoolData['lng']) : null,
        'notes' => '',
        'visit_date' => '',
        'contact_result' => '',
        'priority' => '',
        'follow_up' => '',
        'visited' => false,
        '_geocoded' => false,
        '_coords_verified' => false,
        '_geocode_status' => 'none'
    ];

    // اگر مختصات وارد شده باشد، وضعیت verified می‌شود
    if (!empty($newSchool['lat']) && !empty($newSchool['lng']) && is_numeric($newSchool['lat']) && is_numeric($newSchool['lng']) && $newSchool['lat'] != 0 && $newSchool['lng'] != 0) {
        $newSchool['_coords_verified'] = true;
        $newSchool['_geocode_status'] = 'verified';
    }

    // اضافه کردن به آرایه
    $schools[] = $newSchool;

    // ذخیره فایل
    if (file_put_contents($dataFile, json_encode($schools, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))) {
        echo json_encode(['success' => true, 'school' => $newSchool]);
    } else {
        echo json_encode(['success' => false, 'error' => 'خطا در ذخیره فایل']);
    }
    exit;
}

// =============================================================
// اکشن ۹: دریافت لیست همه مدارس با جستجوی سراسری و صفحه‌بندی
// =============================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_all_schools') {
    header('Content-Type: application/json; charset=utf-8');

    if (!file_exists($dataFile)) {
        echo json_encode(['error' => 'فایل data.json یافت نشد']);
        exit;
    }

    $json = file_get_contents($dataFile);
    $schools = json_decode($json, true);
    if (!is_array($schools)) {
        echo json_encode(['error' => 'فرمت داده نامعتبر']);
        exit;
    }

    // پارامترها
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $perPage = isset($_GET['per_page']) ? intval($_GET['per_page']) : 20;

    if ($page < 1) $page = 1;
    if ($perPage < 1) $perPage = 20;

    // فیلتر بر اساس جستجوی سراسری
    $filtered = $schools;
    if (!empty($search)) {
        $filtered = array_filter($schools, function($s) use ($search) {
            $text = json_encode($s, JSON_UNESCAPED_UNICODE);
            return stripos($text, $search) !== false;
        });
        // بازآرایی ایندکس‌ها
        $filtered = array_values($filtered);
    }

    $total = count($filtered);
    $offset = ($page - 1) * $perPage;
    $pagedData = array_slice($filtered, $offset, $perPage);

    echo json_encode([
        'data' => $pagedData,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// =============================================================
// نمایش صفحه اصلی (map.html)
// =============================================================
if (file_exists(__DIR__ . '/map.html')) {
    readfile(__DIR__ . '/map.html');
} else {
    echo "فایل map.html یافت نشد.";
}