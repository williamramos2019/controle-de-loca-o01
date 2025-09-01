<?php
// #####################################################################
// # SISTEMA DE GESTÃO DE ESTOQUE - VERSÃO 2.0
// # Sistema standalone para cPanel com autenticação, upload XML/imagens
// # e filtros avançados
// #####################################################################

// Configurações de segurança
error_reporting(0);
ini_set('display_errors', 0);
session_start();

// Configurações do sistema
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'admin123');
define('UPLOAD_DIR', 'uploads');

// Criar diretório de uploads se não existir
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

$db_file = 'gestao.sqlite';
$is_new_db = !file_exists($db_file);

try {
    $pdo = new PDO('sqlite:' . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON;');

    if ($is_new_db) {
        // Tabela de empresas
        $pdo->exec("CREATE TABLE IF NOT EXISTS companies (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            phone TEXT,
            cnpj TEXT UNIQUE,
            email TEXT,
            address TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Tabela de movimentações
        $pdo->exec("CREATE TABLE IF NOT EXISTS movements (
            id TEXT PRIMARY KEY,
            company_id TEXT NOT NULL,
            type TEXT NOT NULL,
            date TEXT NOT NULL,
            nfe TEXT,
            products TEXT NOT NULL,
            total_value DECIMAL(10,2) DEFAULT 0,
            image_path TEXT,
            xml_path TEXT,
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
        )");

        // Tabela de logs
        $pdo->exec("CREATE TABLE IF NOT EXISTS logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            action TEXT NOT NULL,
            details TEXT,
            ip_address TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    die(json_encode(['status' => 'error', 'message' => 'Erro no banco de dados: ' . $e->getMessage()]));
}

// Função para log de ações
function logAction($action, $details = '') {
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO logs (action, details, ip_address) VALUES (?, ?, ?)");
        $stmt->execute([$action, $details, $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
    } catch (Exception $e) {
        // Log silencioso em caso de erro
    }
}

// Verificar autenticação
function isAuthenticated() {
    return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
}

// Processar login
if (isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($username === ADMIN_USER && $password === ADMIN_PASS) {
        $_SESSION['authenticated'] = true;
        $_SESSION['login_time'] = time();
        logAction('LOGIN', 'Usuário logado com sucesso');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $login_error = 'Usuário ou senha inválidos';
        logAction('LOGIN_FAILED', 'Tentativa de login com credenciais inválidas');
    }
}

// Processar logout
if (isset($_GET['logout'])) {
    logAction('LOGOUT', 'Usuário fez logout');
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// API Router
if (isset($_GET['action']) && isAuthenticated()) {
    $action = $_GET['action'];
    header('Content-Type: application/json');

    try {
        switch ($action) {
            case 'upload_xml':
                if (isset($_FILES['xmlfile']) && $_FILES['xmlfile']['error'] == 0) {
                    $xmlContent = file_get_contents($_FILES['xmlfile']['tmp_name']);
                    $xml = simplexml_load_string($xmlContent);

                    if ($xml === false) {
                        throw new Exception('Arquivo XML inválido.');
                    }

                    // Verificar se é NFe
                    if (!isset($xml->NFe->infNFe)) {
                        throw new Exception('Arquivo não é uma NF-e válida.');
                    }

                    $infNFe = $xml->NFe->infNFe;
                    $emit = $infNFe->emit;
                    $ide = $infNFe->ide;
                    $total = $infNFe->total->ICMSTot ?? null;

                    // Salvar XML
                    $xmlFileName = 'xml_' . uniqid() . '.xml';
                    $xmlPath = UPLOAD_DIR . '/' . $xmlFileName;
                    file_put_contents($xmlPath, $xmlContent);

                    $result = [
                        'company' => [
                            'cnpj' => (string)$emit->CNPJ,
                            'name' => (string)$emit->xNome,
                            'phone' => (string)($emit->enderEmit->fone ?? ''),
                            'email' => (string)($emit->email ?? ''),
                            'address' => trim((string)($emit->enderEmit->xLgr ?? '') . ', ' . 
                                       (string)($emit->enderEmit->nro ?? '') . ' - ' . 
                                       (string)($emit->enderEmit->xBairro ?? '') . ' - ' . 
                                       (string)($emit->enderEmit->xMun ?? '') . '/' . 
                                       (string)($emit->enderEmit->UF ?? ''))
                        ],
                        'movement' => [
                            'nfe' => (string)$ide->nNF,
                            'date' => substr((string)$ide->dhEmi, 0, 10),
                            'total_value' => $total ? (float)$total->vNF : 0,
                            'xml_path' => $xmlPath
                        ],
                        'products' => []
                    ];

                    // Extrair produtos
                    foreach ($infNFe->det as $item) {
                        $prod = $item->prod;
                        $result['products'][] = [
                            'name' => (string)$prod->xProd,
                            'quantity' => (float)$prod->qCom,
                            'price' => (float)$prod->vUnCom,
                            'total' => (float)($prod->qCom * $prod->vUnCom),
                            'unit' => (string)$prod->uCom,
                            'code' => (string)($prod->cProd ?? '')
                        ];
                    }
                    
                    logAction('XML_UPLOAD', 'XML processado: NFe ' . $result['movement']['nfe']);
                    echo json_encode(['status' => 'success', 'data' => $result]);

                } else {
                    throw new Exception('Erro ao fazer upload do arquivo XML.');
                }
                break;

            case 'upload_image':
                if (isset($_FILES['imagefile']) && $_FILES['imagefile']['error'] == 0) {
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    $fileType = $_FILES['imagefile']['type'];
                    
                    if (!in_array($fileType, $allowedTypes)) {
                        throw new Exception('Tipo de arquivo não permitido. Use apenas JPG, PNG, GIF ou WebP.');
                    }

                    $maxSize = 5 * 1024 * 1024; // 5MB
                    if ($_FILES['imagefile']['size'] > $maxSize) {
                        throw new Exception('Arquivo muito grande. Tamanho máximo: 5MB.');
                    }

                    $extension = pathinfo($_FILES['imagefile']['name'], PATHINFO_EXTENSION);
                    $fileName = 'img_' . uniqid() . '.' . $extension;
                    $filePath = UPLOAD_DIR . '/' . $fileName;

                    if (move_uploaded_file($_FILES['imagefile']['tmp_name'], $filePath)) {
                        logAction('IMAGE_UPLOAD', 'Imagem carregada: ' . $fileName);
                        echo json_encode(['status' => 'success', 'path' => $filePath, 'filename' => $fileName]);
                    } else {
                        throw new Exception('Erro ao salvar o arquivo de imagem.');
                    }
                } else {
                    throw new Exception('Erro ao fazer upload da imagem.');
                }
                break;

            case 'get_data':
                $filters = $_GET;
                
                // Buscar empresas
                $companies_stmt = $pdo->query("SELECT * FROM companies ORDER BY name ASC");
                $companies = $companies_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Buscar movimentações com filtros
                $where_conditions = [];
                $params = [];
                
                if (!empty($filters['company_filter'])) {
                    $where_conditions[] = "m.company_id = ?";
                    $params[] = $filters['company_filter'];
                }
                
                if (!empty($filters['type_filter'])) {
                    $where_conditions[] = "m.type = ?";
                    $params[] = $filters['type_filter'];
                }
                
                if (!empty($filters['start_date'])) {
                    $where_conditions[] = "m.date >= ?";
                    $params[] = $filters['start_date'];
                }
                
                if (!empty($filters['end_date'])) {
                    $where_conditions[] = "m.date <= ?";
                    $params[] = $filters['end_date'];
                }
                
                if (!empty($filters['min_value'])) {
                    $where_conditions[] = "m.total_value >= ?";
                    $params[] = $filters['min_value'];
                }
                
                if (!empty($filters['max_value'])) {
                    $where_conditions[] = "m.total_value <= ?";
                    $params[] = $filters['max_value'];
                }

                if (!empty($filters['product_filter'])) {
                    $where_conditions[] = "m.products LIKE ?";
                    $params[] = '%' . $filters['product_filter'] . '%';
                }
                
                $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
                
                $movements_sql = "SELECT m.*, c.name as company_name 
                                 FROM movements m 
                                 LEFT JOIN companies c ON m.company_id = c.id 
                                 $where_clause 
                                 ORDER BY m.date DESC, m.created_at DESC";
                
                $movements_stmt = $pdo->prepare($movements_sql);
                $movements_stmt->execute($params);
                $movements = $movements_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Processar movimentações
                $movements = array_map(function($m) {
                    $m['products'] = json_decode($m['products'], true);
                    return $m;
                }, $movements);
                
                echo json_encode([
                    'companies' => $companies,
                    'movements' => $movements
                ]);
                break;

            case 'get_stock_balance':
                $company_filter = $_GET['company_filter'] ?? '';
                
                // Buscar todas as movimentações para calcular saldo
                $where_conditions = [];
                $params = [];
                
                if (!empty($company_filter)) {
                    $where_conditions[] = "m.company_id = ?";
                    $params[] = $company_filter;
                }
                
                $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
                
                $movements_sql = "SELECT m.*, c.name as company_name 
                                 FROM movements m 
                                 LEFT JOIN companies c ON m.company_id = c.id 
                                 $where_clause 
                                 ORDER BY m.date ASC";
                
                $movements_stmt = $pdo->prepare($movements_sql);
                $movements_stmt->execute($params);
                $movements = $movements_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Calcular saldo por produto
                $stock_balance = [];
                $total_value = 0;
                $total_quantity = 0;
                
                foreach ($movements as $movement) {
                    $products = json_decode($movement['products'], true) ?: [];
                    $multiplier = 1;
                    
                    // Definir multiplicador baseado no tipo de movimento
                    switch ($movement['type']) {
                        case 'entrada':
                        case 'devolucao':
                            $multiplier = 1;
                            break;
                        case 'saida':
                            $multiplier = -1;
                            break;
                    }
                    
                    foreach ($products as $product) {
                        $product_name = $product['name'] ?? 'Produto sem nome';
                        $product_code = $product['code'] ?? '';
                        $quantity = (float)($product['quantity'] ?? 0);
                        $price = (float)($product['price'] ?? 0);
                        $product_total = (float)($product['total'] ?? ($quantity * $price));
                        
                        // Usar código + nome como chave única
                        $product_key = $product_code . '|' . $product_name;
                        
                        if (!isset($stock_balance[$product_key])) {
                            $stock_balance[$product_key] = [
                                'name' => $product_name,
                                'code' => $product_code,
                                'quantity' => 0,
                                'avg_price' => $price,
                                'total_value' => 0,
                                'company' => $movement['company_name'] ?? 'N/A'
                            ];
                        }
                        
                        // Atualizar quantidade e valor
                        $new_quantity = $stock_balance[$product_key]['quantity'] + ($quantity * $multiplier);
                        $stock_balance[$product_key]['quantity'] = $new_quantity;
                        
                        // Calcular preço médio apenas para entradas positivas
                        if ($multiplier > 0 && $quantity > 0) {
                            $current_total = $stock_balance[$product_key]['avg_price'] * 
                                           ($stock_balance[$product_key]['quantity'] - ($quantity * $multiplier));
                            $new_total = $current_total + $product_total;
                            $total_items = $stock_balance[$product_key]['quantity'];
                            
                            if ($total_items > 0) {
                                $stock_balance[$product_key]['avg_price'] = $new_total / $total_items;
                            }
                        }
                        
                        // Calcular valor total atual do produto
                        $stock_balance[$product_key]['total_value'] = 
                            $stock_balance[$product_key]['quantity'] * 
                            $stock_balance[$product_key]['avg_price'];
                    }
                }
                
                // Remover produtos com saldo zero e calcular totais
                $final_balance = [];
                foreach ($stock_balance as $product) {
                    if ($product['quantity'] > 0) {
                        $final_balance[] = $product;
                        $total_quantity += $product['quantity'];
                        $total_value += $product['total_value'];
                    }
                }
                
                // Ordenar por nome do produto
                usort($final_balance, function($a, $b) {
                    return strcmp($a['name'], $b['name']);
                });
                
                echo json_encode([
                    'status' => 'success',
                    'data' => [
                        'products' => $final_balance,
                        'summary' => [
                            'total_products' => count($final_balance),
                            'total_quantity' => $total_quantity,
                            'total_value' => $total_value
                        ]
                    ]
                ]);
                break;

            case 'save_company':
                $data = json_decode(file_get_contents('php://input'), true);
                
                // Validações
                if (empty($data['name'])) {
                    throw new Exception('Nome da empresa é obrigatório.');
                }
                
                if (!empty($data['cnpj'])) {
                    // Validar CNPJ básico
                    $cnpj = preg_replace('/[^0-9]/', '', $data['cnpj']);
                    if (strlen($cnpj) != 14) {
                        throw new Exception('CNPJ deve ter 14 dígitos.');
                    }
                    
                    // Verificar se CNPJ já existe
                    $stmt = $pdo->prepare("SELECT id FROM companies WHERE cnpj = ? AND id != ?");
                    $stmt->execute([$cnpj, $data['id'] ?: '']);
                    if ($stmt->fetch()) {
                        throw new Exception("CNPJ já cadastrado para outra empresa.");
                    }
                    $data['cnpj'] = $cnpj;
                }

                if ($data['id']) {
                    // Atualizar
                    $sql = "UPDATE companies SET name=?, phone=?, cnpj=?, email=?, address=? WHERE id=?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        $data['name'], $data['phone'], $data['cnpj'], 
                        $data['email'], $data['address'], $data['id']
                    ]);
                    logAction('COMPANY_UPDATE', 'Empresa atualizada: ' . $data['name']);
                } else {
                    // Inserir
                    $id = 'c_' . uniqid();
                    $sql = "INSERT INTO companies (id, name, phone, cnpj, email, address) VALUES (?, ?, ?, ?, ?, ?)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        $id, $data['name'], $data['phone'], $data['cnpj'], 
                        $data['email'], $data['address']
                    ]);
                    logAction('COMPANY_CREATE', 'Nova empresa: ' . $data['name']);
                }
                
                echo json_encode(['status' => 'success']);
                break;

            case 'save_movement':
                $data = json_decode(file_get_contents('php://input'), true);
                
                // Validações
                if (empty($data['companyId'])) {
                    throw new Exception('Empresa é obrigatória.');
                }
                
                if (empty($data['products']) || !is_array($data['products'])) {
                    throw new Exception('Pelo menos um produto deve ser informado.');
                }

                // Calcular valor total
                $total_value = 0;
                foreach ($data['products'] as $product) {
                    if (isset($product['total'])) {
                        $total_value += (float)$product['total'];
                    }
                }

                if ($data['id']) {
                    // Atualizar
                    $sql = "UPDATE movements SET company_id=?, type=?, date=?, nfe=?, products=?, total_value=?, image_path=?, notes=? WHERE id=?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        $data['companyId'], $data['type'], $data['date'], $data['nfe'],
                        json_encode($data['products']), $total_value, 
                        $data['image_path'] ?? null, $data['notes'] ?? '', $data['id']
                    ]);
                    logAction('MOVEMENT_UPDATE', 'Movimentação atualizada: ' . $data['id']);
                } else {
                    // Inserir
                    $id = 'm_' . uniqid();
                    $sql = "INSERT INTO movements (id, company_id, type, date, nfe, products, total_value, image_path, xml_path, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        $id, $data['companyId'], $data['type'], $data['date'], $data['nfe'],
                        json_encode($data['products']), $total_value, 
                        $data['image_path'] ?? null, $data['xml_path'] ?? null, $data['notes'] ?? ''
                    ]);
                    logAction('MOVEMENT_CREATE', 'Nova movimentação: ' . $data['type'] . ' - NFe: ' . $data['nfe']);
                }
                
                echo json_encode(['status' => 'success']);
                break;

            case 'delete_company':
                $data = json_decode(file_get_contents('php://input'), true);
                
                // Verificar se há movimentações
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM movements WHERE company_id = ?");
                $stmt->execute([$data['id']]);
                $count = $stmt->fetchColumn();
                
                if ($count > 0) {
                    throw new Exception('Não é possível excluir empresa com movimentações. Exclua as movimentações primeiro.');
                }
                
                $stmt = $pdo->prepare("DELETE FROM companies WHERE id = ?");
                $stmt->execute([$data['id']]);
                logAction('COMPANY_DELETE', 'Empresa excluída: ' . $data['id']);
                
                echo json_encode(['status' => 'success']);
                break;

            case 'delete_movement':
                $data = json_decode(file_get_contents('php://input'), true);
                
                // Buscar arquivos associados para exclusão
                $stmt = $pdo->prepare("SELECT image_path, xml_path FROM movements WHERE id = ?");
                $stmt->execute([$data['id']]);
                $files = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Excluir movimentação
                $stmt = $pdo->prepare("DELETE FROM movements WHERE id = ?");
                $stmt->execute([$data['id']]);
                
                // Excluir arquivos físicos
                if ($files) {
                    if ($files['image_path'] && file_exists($files['image_path'])) {
                        unlink($files['image_path']);
                    }
                    if ($files['xml_path'] && file_exists($files['xml_path'])) {
                        unlink($files['xml_path']);
                    }
                }
                
                logAction('MOVEMENT_DELETE', 'Movimentação excluída: ' . $data['id']);
                echo json_encode(['status' => 'success']);
                break;

            case 'export_data':
                $companies_stmt = $pdo->query("SELECT * FROM companies ORDER BY name ASC");
                $companies = $companies_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $movements_stmt = $pdo->query("SELECT m.*, c.name as company_name FROM movements m LEFT JOIN companies c ON m.company_id = c.id ORDER BY m.date DESC");
                $movements = $movements_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $export_data = [
                    'export_date' => date('Y-m-d H:i:s'),
                    'companies' => $companies,
                    'movements' => array_map(function($m) {
                        $m['products'] = json_decode($m['products'], true);
                        return $m;
                    }, $movements)
                ];
                
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="gestao_estoque_' . date('Y-m-d_H-i-s') . '.json"');
                
                logAction('DATA_EXPORT', 'Dados exportados');
                echo json_encode($export_data, JSON_PRETTY_PRINT);
                break;

            default:
                throw new Exception("Ação desconhecida: " . htmlspecialchars($action));
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }

    exit;
}

// Se não estiver autenticado, mostrar tela de login
if (!isAuthenticated()) {
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Sistema de Gestão de Estoque</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen flex items-center justify-center">
    <div class="bg-white p-8 rounded-xl shadow-2xl w-full max-w-md">
        <div class="text-center mb-8">
            <i class="fas fa-boxes text-5xl text-blue-600 mb-4"></i>
            <h1 class="text-2xl font-bold text-gray-900">Sistema de Gestão</h1>
            <p class="text-gray-600">Faça login para acessar o sistema</p>
        </div>
        
        <?php if (isset($login_error)): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <i class="fas fa-exclamation-triangle mr-2"></i><?= htmlspecialchars($login_error) ?>
        </div>
        <?php endif; ?>
        
        <form method="POST" class="space-y-6">
            <div>
                <label for="username" class="block text-sm font-medium text-gray-700 mb-2">Usuário</label>
                <div class="relative">
                    <i class="fas fa-user absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    <input type="text" id="username" name="username" required 
                           class="w-full pl-10 pr-3 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="Digite seu usuário">
                </div>
            </div>
            
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-2">Senha</label>
                <div class="relative">
                    <i class="fas fa-lock absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    <input type="password" id="password" name="password" required 
                           class="w-full pl-10 pr-3 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="Digite sua senha">
                </div>
            </div>
            
            <button type="submit" name="login" 
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-lg transition duration-200 flex items-center justify-center">
                <i class="fas fa-sign-in-alt mr-2"></i>Entrar
            </button>
        </form>
        
        <div class="mt-6 text-center text-sm text-gray-500">
            Sistema seguro de gestão de estoque
        </div>
    </div>
</body>
</html>
<?php
exit;
}

// Interface principal (usuário autenticado)
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Gestão de Estoque</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .fade-in { animation: fadeIn 0.3s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .modal-backdrop { backdrop-filter: blur(4px); }
        html, body { height: 100%; overflow-x: hidden; scroll-behavior: smooth; }
        .container-main { max-width: 100vw; overflow-x: hidden; }
        .chart-container { height: 300px; max-height: 300px; }
        .table-container { max-height: 500px; overflow-y: auto; }
        .tab-btn.active { border-bottom-width: 2px; border-color: #3b82f6; color: #2563eb; }
        @media (max-width: 768px) { 
            .container-main { padding: 1rem; } 
            .grid { gap: 1rem; } 
            .table-container { max-height: 300px; }
        }
        .image-preview { max-width: 200px; max-height: 200px; object-fit: cover; }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Header -->
    <header class="bg-white shadow-sm border-b">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-6">
                <div class="flex items-center">
                    <h1 class="text-2xl font-bold text-gray-900">
                        <i class="fas fa-boxes text-blue-600 mr-2"></i>Sistema de Gestão de Estoque
                    </h1>
                </div>
                <div class="flex items-center space-x-4">
                    <div id="syncStatus" class="flex items-center text-sm">
                        <span class="w-2 h-2 bg-green-500 rounded-full mr-2"></span>
                        <span class="text-gray-600">Sistema Online</span>
                    </div>
                    <button id="exportBtn" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center">
                        <i class="fas fa-download mr-2"></i>Exportar
                    </button>
                    <button id="manageCompaniesBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg flex items-center">
                        <i class="fas fa-building mr-2"></i>Gerenciar Empresas
                    </button>
                    <a href="?logout=1" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg flex items-center">
                        <i class="fas fa-sign-out-alt mr-2"></i>Sair
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container-main max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
        <!-- Action Buttons -->
        <div class="mb-4 flex flex-wrap gap-4">
            <button id="importXmlBtn" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg flex items-center">
                <i class="fas fa-upload mr-2"></i>Importar XML
            </button>
            <button id="newEntryBtn" class="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-lg flex items-center">
                <i class="fas fa-plus mr-2"></i>Nova Entrada
            </button>
            <button id="newExitBtn" class="bg-yellow-600 hover:bg-yellow-700 text-white px-6 py-3 rounded-lg flex items-center">
                <i class="fas fa-minus mr-2"></i>Nova Saída
            </button>
            <button id="newReturnBtn" class="bg-purple-600 hover:bg-purple-700 text-white px-6 py-3 rounded-lg flex items-center">
                <i class="fas fa-undo mr-2"></i>Nova Devolução
            </button>
        </div>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-4">
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-green-100">
                        <i class="fas fa-arrow-up text-green-600"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-sm font-medium text-gray-500">Entradas</h3>
                        <p id="totalEntries" class="text-2xl font-semibold text-gray-900">0</p>
                    </div>
                </div>
            </div>
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-yellow-100">
                        <i class="fas fa-arrow-down text-yellow-600"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-sm font-medium text-gray-500">Saídas</h3>
                        <p id="totalExits" class="text-2xl font-semibold text-gray-900">0</p>
                    </div>
                </div>
            </div>
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-purple-100">
                        <i class="fas fa-undo text-purple-600"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-sm font-medium text-gray-500">Devoluções</h3>
                        <p id="totalReturns" class="text-2xl font-semibold text-gray-900">0</p>
                    </div>
                </div>
            </div>
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-blue-100">
                        <i class="fas fa-chart-line text-blue-600"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-sm font-medium text-gray-500">Valor Total</h3>
                        <p id="totalValue" class="text-2xl font-semibold text-gray-900">R$ 0,00</p>
                    </div>
                </div>
            </div>
            <div class="bg-white p-6 rounded-lg shadow cursor-pointer hover:bg-gray-50 transition-colors" onclick="openStockModal()">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-indigo-100">
                        <i class="fas fa-warehouse text-indigo-600"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-sm font-medium text-gray-500">Saldo Estoque <i class="fas fa-external-link-alt text-xs ml-1"></i></h3>
                        <p id="stockBalance" class="text-lg font-semibold text-gray-900">
                            <span id="stockQuantity">0</span> produtos<br>
                            <span id="stockValue" class="text-sm text-gray-600">R$ 0,00</span>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-white p-4 rounded-lg shadow mb-4">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Filtros Avançados</h3>
            <div class="grid grid-cols-1 md:grid-cols-4 lg:grid-cols-6 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Empresa</label>
                    <select id="companyFilter" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Todas as Empresas</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Tipo</label>
                    <select id="typeFilter" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Todos os Tipos</option>
                        <option value="entrada">Entradas</option>
                        <option value="saida">Saídas</option>
                        <option value="devolucao">Devoluções</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Data Inicial</label>
                    <input type="date" id="startDateFilter" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Data Final</label>
                    <input type="date" id="endDateFilter" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Valor Mínimo</label>
                    <input type="number" id="minValueFilter" step="0.01" placeholder="0,00" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Valor Máximo</label>
                    <input type="number" id="maxValueFilter" step="0.01" placeholder="0,00" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>
            <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Buscar Produto</label>
                    <input type="text" id="productFilter" placeholder="Nome do produto..." class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div class="flex items-end">
                    <button id="applyFilters" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg flex items-center">
                        <i class="fas fa-filter mr-2"></i>Aplicar Filtros
                    </button>
                </div>
                <div class="flex items-end">
                    <button id="clearFilters" class="bg-gray-600 hover:bg-gray-700 text-white px-6 py-2 rounded-lg flex items-center">
                        <i class="fas fa-times mr-2"></i>Limpar Filtros
                    </button>
                </div>
            </div>
        </div>

        <!-- Movements Table -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Movimentações</h3>
            </div>
            <div class="table-container">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50 sticky top-0">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Data</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Empresa</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tipo</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">NFe</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Produtos</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Valor Total</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Anexos</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
                        </tr>
                    </thead>
                    <tbody id="movementsTable" class="bg-white divide-y divide-gray-200">
                        <!-- Movements will be loaded here -->
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Modals -->
    <!-- XML Import Modal -->
    <div id="xmlModal" class="fixed inset-0 bg-black bg-opacity-50 modal-backdrop hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-screen overflow-y-auto">
                <div class="px-6 py-4 border-b">
                    <h3 class="text-lg font-medium">Importar XML de NF-e</h3>
                </div>
                <div class="p-6">
                    <form id="xmlForm" enctype="multipart/form-data">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Arquivo XML da NF-e
                            </label>
                            <input type="file" id="xmlFile" name="xmlfile" accept=".xml" required
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div id="xmlPreview" class="hidden">
                            <h4 class="text-md font-medium text-gray-700 mb-2">Dados Encontrados:</h4>
                            <div id="xmlPreviewContent" class="bg-gray-50 p-4 rounded-lg text-sm">
                                <!-- XML preview will be shown here -->
                            </div>
                        </div>
                    </form>
                </div>
                <div class="px-6 py-4 border-t bg-gray-50 flex justify-end space-x-2">
                    <button id="cancelXmlBtn" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-lg hover:bg-gray-300">
                        Cancelar
                    </button>
                    <button id="processXmlBtn" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        <i class="fas fa-upload mr-2"></i>Processar XML
                    </button>
                    <button id="saveFromXmlBtn" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 hidden">
                        <i class="fas fa-save mr-2"></i>Salvar Movimentação
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Movement Modal -->
    <div id="movementModal" class="fixed inset-0 bg-black bg-opacity-50 modal-backdrop hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-screen overflow-y-auto">
                <div class="px-6 py-4 border-b">
                    <h3 id="movementModalTitle" class="text-lg font-medium">Nova Movimentação</h3>
                </div>
                <div class="p-6">
                    <form id="movementForm">
                        <input type="hidden" id="movementId">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Empresa *</label>
                                <select id="movementCompany" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500">
                                    <option value="">Selecione uma empresa</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Tipo de Movimentação *</label>
                                <select id="movementType" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500">
                                    <option value="">Selecione o tipo</option>
                                    <option value="entrada">Entrada</option>
                                    <option value="saida">Saída</option>
                                    <option value="devolucao">Devolução</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Data *</label>
                                <input type="date" id="movementDate" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Número NF-e</label>
                                <input type="text" id="movementNfe" placeholder="Ex: 123456" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>
                        
                        <!-- Image Upload -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Imagem da Nota Fiscal</label>
                            <div class="flex items-center space-x-4">
                                <input type="file" id="movementImage" accept="image/*" class="flex-1 border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500">
                                <button type="button" id="uploadImageBtn" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                                    <i class="fas fa-upload mr-2"></i>Upload
                                </button>
                            </div>
                            <div id="imagePreview" class="mt-2 hidden">
                                <img id="previewImg" src="" alt="Preview" class="image-preview rounded-lg border">
                            </div>
                            <input type="hidden" id="imagePath">
                        </div>

                        <!-- Products -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Produtos *</label>
                            <div id="productsList">
                                <!-- Products will be added here -->
                            </div>
                            <button type="button" id="addProductBtn" class="mt-2 bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center">
                                <i class="fas fa-plus mr-2"></i>Adicionar Produto
                            </button>
                        </div>

                        <!-- Notes -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Observações</label>
                            <textarea id="movementNotes" rows="3" placeholder="Observações adicionais..." 
                                      class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500"></textarea>
                        </div>
                    </form>
                </div>
                <div class="px-6 py-4 border-t bg-gray-50 flex justify-end space-x-2">
                    <button id="cancelMovementBtn" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-lg hover:bg-gray-300">
                        Cancelar
                    </button>
                    <button id="saveMovementBtn" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        <i class="fas fa-save mr-2"></i>Salvar Movimentação
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Companies Modal -->
    <div id="companiesModal" class="fixed inset-0 bg-black bg-opacity-50 modal-backdrop hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-6xl w-full max-h-screen overflow-y-auto">
                <div class="px-6 py-4 border-b">
                    <h3 class="text-lg font-medium">Gerenciar Empresas</h3>
                </div>
                <div class="p-6">
                    <div class="mb-4">
                        <button id="newCompanyBtn" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center">
                            <i class="fas fa-plus mr-2"></i>Nova Empresa
                        </button>
                    </div>
                    <div class="table-container">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nome</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">CNPJ</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Telefone</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Ações</th>
                                </tr>
                            </thead>
                            <tbody id="companiesTable" class="bg-white divide-y divide-gray-200">
                                <!-- Companies will be loaded here -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="px-6 py-4 border-t bg-gray-50 flex justify-end">
                    <button id="closeCompaniesBtn" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-lg hover:bg-gray-300">
                        Fechar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Company Form Modal -->
    <div id="companyModal" class="fixed inset-0 bg-black bg-opacity-50 modal-backdrop hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full">
                <div class="px-6 py-4 border-b">
                    <h3 id="companyModalTitle" class="text-lg font-medium">Nova Empresa</h3>
                </div>
                <div class="p-6">
                    <form id="companyForm">
                        <input type="hidden" id="companyId">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Nome da Empresa *</label>
                                <input type="text" id="companyName" required placeholder="Ex: Empresa LTDA" 
                                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">CNPJ</label>
                                <input type="text" id="companyCnpj" placeholder="Ex: 12.345.678/0001-90" 
                                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Telefone</label>
                                <input type="text" id="companyPhone" placeholder="Ex: (11) 99999-9999" 
                                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                                <input type="email" id="companyEmail" placeholder="Ex: contato@empresa.com" 
                                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Endereço</label>
                                <textarea id="companyAddress" rows="2" placeholder="Endereço completo..." 
                                          class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500"></textarea>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="px-6 py-4 border-t bg-gray-50 flex justify-end space-x-2">
                    <button id="cancelCompanyBtn" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-lg hover:bg-gray-300">
                        Cancelar
                    </button>
                    <button id="saveCompanyBtn" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        <i class="fas fa-save mr-2"></i>Salvar Empresa
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Movement Products Modal -->
    <div id="movementProductsModal" class="fixed inset-0 bg-black bg-opacity-50 modal-backdrop hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-5xl w-full max-h-screen overflow-y-auto">
                <div class="px-6 py-4 border-b">
                    <h3 id="movementProductsTitle" class="text-lg font-medium">Produtos da Movimentação</h3>
                </div>
                <div class="p-6">
                    <div id="movementProductsInfo" class="mb-4 bg-blue-50 p-4 rounded-lg">
                        <!-- Movement info will be loaded here -->
                    </div>
                    <div class="table-container">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Código</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Produto</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Unidade</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Quantidade</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Preço Unit.</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total</th>
                                </tr>
                            </thead>
                            <tbody id="movementProductsTable" class="bg-white divide-y divide-gray-200">
                                <!-- Products will be loaded here -->
                            </tbody>
                        </table>
                    </div>
                    <div id="movementProductsTotals" class="mt-4 p-4 bg-green-50 rounded-lg">
                        <!-- Totals will be shown here -->
                    </div>
                </div>
                <div class="px-6 py-4 border-t bg-gray-50 flex justify-end">
                    <button id="closeMovementProductsBtn" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-lg hover:bg-gray-300">
                        Fechar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Stock Balance Modal -->
    <div id="stockModal" class="fixed inset-0 bg-black bg-opacity-50 modal-backdrop hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-6xl w-full max-h-screen overflow-y-auto">
                <div class="px-6 py-4 border-b">
                    <h3 class="text-lg font-medium">Saldo de Estoque Detalhado</h3>
                </div>
                <div class="p-6">
                    <div class="mb-4 bg-blue-50 p-4 rounded-lg">
                        <div class="grid grid-cols-3 gap-4 text-center">
                            <div>
                                <span class="text-sm text-gray-600">Total de Produtos</span>
                                <p id="stockModalTotalProducts" class="text-2xl font-bold text-blue-600">0</p>
                            </div>
                            <div>
                                <span class="text-sm text-gray-600">Quantidade Total</span>
                                <p id="stockModalTotalQuantity" class="text-2xl font-bold text-green-600">0</p>
                            </div>
                            <div>
                                <span class="text-sm text-gray-600">Valor Total</span>
                                <p id="stockModalTotalValue" class="text-2xl font-bold text-purple-600">R$ 0,00</p>
                            </div>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Código</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Produto</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Quantidade</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Preço Médio</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Valor Total</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Empresa</th>
                                </tr>
                            </thead>
                            <tbody id="stockTable" class="bg-white divide-y divide-gray-200">
                                <!-- Stock items will be loaded here -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="px-6 py-4 border-t bg-gray-50 flex justify-end">
                    <button id="closeStockBtn" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-lg hover:bg-gray-300">
                        Fechar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50">
        <div class="bg-white p-6 rounded-lg shadow-xl">
            <div class="flex items-center">
                <i class="fas fa-spinner fa-spin text-blue-600 text-2xl mr-3"></i>
                <span class="text-lg">Processando...</span>
            </div>
        </div>
    </div>

    <script>
        // Global variables
        let companies = [];
        let movements = [];
        let currentXmlData = null;
        let currentImagePath = null;

        // Initialize the application
        document.addEventListener('DOMContentLoaded', function() {
            loadData();
            setupEventListeners();
            setDefaultDate();
        });

        // Set default date to today
        function setDefaultDate() {
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('movementDate').value = today;
        }

        // Setup event listeners
        function setupEventListeners() {
            // Main action buttons
            document.getElementById('importXmlBtn').addEventListener('click', () => openModal('xmlModal'));
            document.getElementById('newEntryBtn').addEventListener('click', () => openMovementModal('entrada'));
            document.getElementById('newExitBtn').addEventListener('click', () => openMovementModal('saida'));
            document.getElementById('newReturnBtn').addEventListener('click', () => openMovementModal('devolucao'));
            document.getElementById('manageCompaniesBtn').addEventListener('click', () => openModal('companiesModal'));
            document.getElementById('exportBtn').addEventListener('click', exportData);

            // XML modal events
            document.getElementById('xmlFile').addEventListener('change', handleXmlFile);
            document.getElementById('processXmlBtn').addEventListener('click', processXml);
            document.getElementById('saveFromXmlBtn').addEventListener('click', saveFromXml);
            document.getElementById('cancelXmlBtn').addEventListener('click', () => closeModal('xmlModal'));

            // Movement modal events
            document.getElementById('addProductBtn').addEventListener('click', addProductRow);
            document.getElementById('uploadImageBtn').addEventListener('click', uploadImage);
            document.getElementById('saveMovementBtn').addEventListener('click', saveMovement);
            document.getElementById('cancelMovementBtn').addEventListener('click', () => closeModal('movementModal'));

            // Company modal events
            document.getElementById('newCompanyBtn').addEventListener('click', () => openCompanyModal());
            document.getElementById('saveCompanyBtn').addEventListener('click', saveCompany);
            document.getElementById('cancelCompanyBtn').addEventListener('click', () => closeModal('companyModal'));
            document.getElementById('closeCompaniesBtn').addEventListener('click', () => closeModal('companiesModal'));

            // Stock modal events
            document.getElementById('closeStockBtn').addEventListener('click', () => closeModal('stockModal'));

            // Movement products modal events
            document.getElementById('closeMovementProductsBtn').addEventListener('click', () => closeModal('movementProductsModal'));

            // Filter events
            document.getElementById('applyFilters').addEventListener('click', applyFilters);
            document.getElementById('clearFilters').addEventListener('click', clearFilters);

            // Auto-apply filters on change
            ['companyFilter', 'typeFilter', 'startDateFilter', 'endDateFilter', 'minValueFilter', 'maxValueFilter', 'productFilter'].forEach(id => {
                document.getElementById(id).addEventListener('change', applyFilters);
            });
        }

        // Load data from server
        async function loadData() {
            try {
                showLoading();
                const filters = getFilters();
                const queryString = new URLSearchParams(filters).toString();
                const response = await fetch(`?action=get_data&${queryString}`);
                const data = await response.json();

                if (data.companies && data.movements) {
                    companies = data.companies;
                    movements = data.movements;
                    
                    updateCompanySelects();
                    updateCompaniesTable();
                    updateMovementsTable();
                    updateStatistics();
                } else {
                    throw new Error('Dados inválidos recebidos do servidor');
                }
            } catch (error) {
                showError('Erro ao carregar dados: ' + error.message);
            } finally {
                hideLoading();
            }
        }

        // Get current filter values
        function getFilters() {
            return {
                company_filter: document.getElementById('companyFilter').value,
                type_filter: document.getElementById('typeFilter').value,
                start_date: document.getElementById('startDateFilter').value,
                end_date: document.getElementById('endDateFilter').value,
                min_value: document.getElementById('minValueFilter').value,
                max_value: document.getElementById('maxValueFilter').value,
                product_filter: document.getElementById('productFilter').value
            };
        }

        // Apply filters
        function applyFilters() {
            loadData();
        }

        // Clear all filters
        function clearFilters() {
            document.getElementById('companyFilter').value = '';
            document.getElementById('typeFilter').value = '';
            document.getElementById('startDateFilter').value = '';
            document.getElementById('endDateFilter').value = '';
            document.getElementById('minValueFilter').value = '';
            document.getElementById('maxValueFilter').value = '';
            document.getElementById('productFilter').value = '';
            loadData();
        }

        // Update company select dropdowns
        function updateCompanySelects() {
            const selects = ['companyFilter', 'movementCompany'];
            selects.forEach(selectId => {
                const select = document.getElementById(selectId);
                const currentValue = select.value;
                
                // Clear existing options (except first one)
                while (select.children.length > 1) {
                    select.removeChild(select.lastChild);
                }
                
                // Add company options
                companies.forEach(company => {
                    const option = document.createElement('option');
                    option.value = company.id;
                    option.textContent = company.name;
                    select.appendChild(option);
                });
                
                // Restore previous value if still valid
                if (currentValue) {
                    select.value = currentValue;
                }
            });
        }

        // Update statistics cards
        function updateStatistics() {
            let totalEntries = 0;
            let totalExits = 0;
            let totalReturns = 0;
            let totalValue = 0;

            movements.forEach(movement => {
                const value = parseFloat(movement.total_value) || 0;
                totalValue += value;

                switch (movement.type) {
                    case 'entrada':
                        totalEntries++;
                        break;
                    case 'saida':
                        totalExits++;
                        break;
                    case 'devolucao':
                        totalReturns++;
                        break;
                }
            });

            document.getElementById('totalEntries').textContent = totalEntries;
            document.getElementById('totalExits').textContent = totalExits;
            document.getElementById('totalReturns').textContent = totalReturns;
            document.getElementById('totalValue').textContent = formatCurrency(totalValue);
            
            // Load stock balance
            loadStockBalance();
        }

        // Load stock balance data
        async function loadStockBalance() {
            try {
                const companyFilter = document.getElementById('companyFilter').value;
                const response = await fetch(`?action=get_stock_balance&company_filter=${companyFilter}`);
                const result = await response.json();
                
                if (result.status === 'success') {
                    const summary = result.data.summary;
                    document.getElementById('stockQuantity').textContent = summary.total_products;
                    document.getElementById('stockValue').textContent = formatCurrency(summary.total_value);
                } else {
                    document.getElementById('stockQuantity').textContent = '0';
                    document.getElementById('stockValue').textContent = 'R$ 0,00';
                }
            } catch (error) {
                console.error('Erro ao carregar saldo de estoque:', error);
                document.getElementById('stockQuantity').textContent = '0';
                document.getElementById('stockValue').textContent = 'R$ 0,00';
            }
        }

        // Open stock modal
        async function openStockModal() {
            try {
                showLoading();
                const companyFilter = document.getElementById('companyFilter').value;
                const response = await fetch(`?action=get_stock_balance&company_filter=${companyFilter}`);
                const result = await response.json();
                
                if (result.status === 'success') {
                    const data = result.data;
                    
                    // Update modal summary
                    document.getElementById('stockModalTotalProducts').textContent = data.summary.total_products;
                    document.getElementById('stockModalTotalQuantity').textContent = Math.round(data.summary.total_quantity);
                    document.getElementById('stockModalTotalValue').textContent = formatCurrency(data.summary.total_value);
                    
                    // Update modal table
                    const tbody = document.getElementById('stockTable');
                    tbody.innerHTML = '';
                    
                    if (data.products.length === 0) {
                        tbody.innerHTML = `
                            <tr>
                                <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                                    <i class="fas fa-inbox text-3xl mb-2 block"></i>
                                    Nenhum produto em estoque
                                </td>
                            </tr>
                        `;
                    } else {
                        data.products.forEach(product => {
                            const row = document.createElement('tr');
                            row.className = 'hover:bg-gray-50';
                            row.innerHTML = `
                                <td class="px-6 py-4 text-sm text-gray-900">${product.code || '-'}</td>
                                <td class="px-6 py-4 text-sm font-medium text-gray-900">${product.name}</td>
                                <td class="px-6 py-4 text-sm text-gray-900">${Math.round(product.quantity)}</td>
                                <td class="px-6 py-4 text-sm text-gray-900">${formatCurrency(product.avg_price)}</td>
                                <td class="px-6 py-4 text-sm font-medium text-gray-900">${formatCurrency(product.total_value)}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">${product.company}</td>
                            `;
                            tbody.appendChild(row);
                        });
                    }
                    
                    openModal('stockModal');
                } else {
                    showError('Erro ao carregar saldo de estoque: ' + (result.message || 'Erro desconhecido'));
                }
            } catch (error) {
                showError('Erro ao carregar saldo de estoque: ' + error.message);
            } finally {
                hideLoading();
            }
        }

        // Update movements table
        function updateMovementsTable() {
            const tbody = document.getElementById('movementsTable');
            tbody.innerHTML = '';

            if (movements.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8" class="px-6 py-4 text-center text-gray-500">
                            <i class="fas fa-inbox text-3xl mb-2 block"></i>
                            Nenhuma movimentação encontrada
                        </td>
                    </tr>
                `;
                return;
            }

            movements.forEach(movement => {
                const row = createMovementRow(movement);
                tbody.appendChild(row);
            });
        }

        // Create movement table row
        function createMovementRow(movement) {
            const tr = document.createElement('tr');
            tr.className = 'hover:bg-gray-50';

            const typeColors = {
                'entrada': 'bg-green-100 text-green-800',
                'saida': 'bg-yellow-100 text-yellow-800',
                'devolucao': 'bg-purple-100 text-purple-800'
            };

            const typeLabels = {
                'entrada': 'Entrada',
                'saida': 'Saída',
                'devolucao': 'Devolução'
            };

            // Products summary
            let productsSummary = '';
            if (movement.products && Array.isArray(movement.products)) {
                const totalProducts = movement.products.length;
                const firstProduct = movement.products[0]?.name || '';
                productsSummary = totalProducts > 1 
                    ? `${firstProduct} (+ ${totalProducts - 1} outros)`
                    : firstProduct;
            }

            // Attachments
            let attachments = '';
            if (movement.image_path) {
                attachments += `<i class="fas fa-image text-blue-600 mr-1" title="Imagem anexada"></i>`;
            }
            if (movement.xml_path) {
                attachments += `<i class="fas fa-file-code text-green-600 mr-1" title="XML anexado"></i>`;
            }

            tr.innerHTML = `
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    ${formatDate(movement.date)}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    ${movement.company_name || 'N/A'}
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full ${typeColors[movement.type]}">
                        ${typeLabels[movement.type]}
                    </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    ${movement.nfe || '-'}
                </td>
                <td class="px-6 py-4 text-sm text-gray-900 max-w-xs">
                    <button onclick="viewMovementProducts('${movement.id}')" 
                            class="text-left hover:text-blue-600 hover:underline cursor-pointer truncate block w-full" 
                            title="Clique para ver todos os produtos">
                        ${productsSummary || 'N/A'} 
                        ${movement.products && movement.products.length > 0 ? `<i class="fas fa-external-link-alt text-xs ml-1"></i>` : ''}
                    </button>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                    ${formatCurrency(movement.total_value)}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    ${attachments || '-'}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                    <button onclick="editMovement('${movement.id}')" class="text-blue-600 hover:text-blue-900 mr-2">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button onclick="deleteMovement('${movement.id}')" class="text-red-600 hover:text-red-900">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            `;

            return tr;
        }

        // Update companies table
        function updateCompaniesTable() {
            const tbody = document.getElementById('companiesTable');
            tbody.innerHTML = '';

            if (companies.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                            <i class="fas fa-building text-3xl mb-2 block"></i>
                            Nenhuma empresa cadastrada
                        </td>
                    </tr>
                `;
                return;
            }

            companies.forEach(company => {
                const tr = document.createElement('tr');
                tr.className = 'hover:bg-gray-50';
                tr.innerHTML = `
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                        ${company.name}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        ${formatCnpj(company.cnpj) || '-'}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        ${company.phone || '-'}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        ${company.email || '-'}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <button onclick="editCompany('${company.id}')" class="text-blue-600 hover:text-blue-900 mr-2">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button onclick="deleteCompany('${company.id}')" class="text-red-600 hover:text-red-900">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        // Handle XML file selection
        function handleXmlFile() {
            const file = document.getElementById('xmlFile').files[0];
            if (file) {
                document.getElementById('xmlPreview').classList.add('hidden');
                document.getElementById('saveFromXmlBtn').classList.add('hidden');
            }
        }

        // Process XML file
        async function processXml() {
            const fileInput = document.getElementById('xmlFile');
            const file = fileInput.files[0];

            if (!file) {
                showError('Selecione um arquivo XML');
                return;
            }

            try {
                showLoading();
                const formData = new FormData();
                formData.append('xmlfile', file);

                const response = await fetch('?action=upload_xml', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.status === 'success') {
                    currentXmlData = result.data;
                    showXmlPreview(result.data);
                    document.getElementById('saveFromXmlBtn').classList.remove('hidden');
                } else {
                    throw new Error(result.message || 'Erro ao processar XML');
                }
            } catch (error) {
                showError('Erro ao processar XML: ' + error.message);
            } finally {
                hideLoading();
            }
        }

        // Show XML preview
        function showXmlPreview(data) {
            const preview = document.getElementById('xmlPreviewContent');
            
            // Calculate totals
            let totalQuantity = 0;
            let totalValue = 0;
            
            const productsHtml = data.products.map((p, index) => {
                totalQuantity += parseFloat(p.quantity || 0);
                totalValue += parseFloat(p.total || (p.quantity * p.price) || 0);
                
                return `
                    <div class="flex items-center justify-between py-2 px-3 ${index % 2 === 0 ? 'bg-gray-50' : 'bg-white'} rounded">
                        <div class="flex-1">
                            <div class="font-medium text-sm text-gray-900">${p.name}</div>
                            ${p.code ? `<div class="text-xs text-gray-500">Código: ${p.code}</div>` : ''}
                            ${p.unit ? `<div class="text-xs text-gray-500">Unidade: ${p.unit}</div>` : ''}
                        </div>
                        <div class="text-right">
                            <div class="text-sm font-medium text-gray-900">Qtd: ${p.quantity}</div>
                            <div class="text-sm text-gray-600">Unit: ${formatCurrency(p.price)}</div>
                            <div class="text-sm font-semibold text-blue-600">Total: ${formatCurrency(p.total || (p.quantity * p.price))}</div>
                        </div>
                    </div>
                `;
            }).join('');

            preview.innerHTML = `
                <div class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 p-4 bg-blue-50 rounded-lg">
                        <div>
                            <h5 class="font-semibold text-gray-700 mb-2">📋 Empresa:</h5>
                            <p class="font-medium">${data.company.name}</p>
                            <p class="text-sm text-gray-600">CNPJ: ${formatCnpj(data.company.cnpj)}</p>
                            ${data.company.phone ? `<p class="text-sm text-gray-600">Tel: ${data.company.phone}</p>` : ''}
                            ${data.company.email ? `<p class="text-sm text-gray-600">Email: ${data.company.email}</p>` : ''}
                        </div>
                        <div>
                            <h5 class="font-semibold text-gray-700 mb-2">📄 NF-e:</h5>
                            <p class="font-medium">Número: ${data.movement.nfe}</p>
                            <p class="text-sm text-gray-600">Data: ${formatDate(data.movement.date)}</p>
                            <p class="text-lg font-bold text-green-600">Total: ${formatCurrency(data.movement.total_value || totalValue)}</p>
                        </div>
                    </div>
                    
                    <div class="p-4 bg-green-50 rounded-lg">
                        <div class="flex items-center justify-between mb-3">
                            <h5 class="font-semibold text-gray-700">📦 Produtos da Nota (${data.products.length} itens)</h5>
                            <div class="text-right text-sm">
                                <div class="font-medium text-gray-700">Quantidade Total: ${Math.round(totalQuantity)}</div>
                                <div class="font-bold text-green-600">Valor Total: ${formatCurrency(totalValue)}</div>
                            </div>
                        </div>
                        <div class="max-h-64 overflow-y-auto space-y-1 border rounded-lg">
                            ${productsHtml}
                        </div>
                    </div>
                </div>
            `;

            document.getElementById('xmlPreview').classList.remove('hidden');
        }

        // View products of a specific movement
        function viewMovementProducts(movementId) {
            const movement = movements.find(m => m.id === movementId);
            if (!movement) {
                showError('Movimentação não encontrada');
                return;
            }

            const modal = document.getElementById('movementProductsModal');
            const title = document.getElementById('movementProductsTitle');
            const info = document.getElementById('movementProductsInfo');
            const table = document.getElementById('movementProductsTable');
            const totals = document.getElementById('movementProductsTotals');

            // Set modal title
            const company = companies.find(c => c.id === movement.company_id);
            title.textContent = `Produtos da Movimentação - ${movement.nfe || 'S/N'}`;

            // Movement info
            const typeLabels = {
                'entrada': 'Entrada',
                'saida': 'Saída', 
                'devolucao': 'Devolução'
            };

            info.innerHTML = `
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <h5 class="font-semibold text-gray-700 mb-1">📋 Empresa:</h5>
                        <p class="font-medium">${company?.name || 'N/A'}</p>
                        ${company?.cnpj ? `<p class="text-sm text-gray-600">CNPJ: ${formatCnpj(company.cnpj)}</p>` : ''}
                    </div>
                    <div>
                        <h5 class="font-semibold text-gray-700 mb-1">📄 Movimentação:</h5>
                        <p class="font-medium">Tipo: ${typeLabels[movement.type] || movement.type}</p>
                        <p class="text-sm text-gray-600">Data: ${formatDate(movement.date)}</p>
                        ${movement.nfe ? `<p class="text-sm text-gray-600">NF-e: ${movement.nfe}</p>` : ''}
                    </div>
                    <div>
                        <h5 class="font-semibold text-gray-700 mb-1">💰 Valor:</h5>
                        <p class="text-xl font-bold text-green-600">${formatCurrency(movement.total_value)}</p>
                        ${movement.notes ? `<p class="text-sm text-gray-600">${movement.notes}</p>` : ''}
                    </div>
                </div>
            `;

            // Products table
            table.innerHTML = '';
            
            if (!movement.products || !Array.isArray(movement.products) || movement.products.length === 0) {
                table.innerHTML = `
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                            <i class="fas fa-box-open text-3xl mb-2 block"></i>
                            Nenhum produto encontrado nesta movimentação
                        </td>
                    </tr>
                `;
            } else {
                let totalQuantity = 0;
                let totalValue = 0;
                
                movement.products.forEach((product, index) => {
                    const quantity = parseFloat(product.quantity || 0);
                    const price = parseFloat(product.price || 0);
                    const total = parseFloat(product.total || (quantity * price));
                    
                    totalQuantity += quantity;
                    totalValue += total;

                    const row = document.createElement('tr');
                    row.className = index % 2 === 0 ? 'bg-gray-50' : 'bg-white';
                    row.innerHTML = `
                        <td class="px-4 py-3 text-sm text-gray-600">${product.code || '-'}</td>
                        <td class="px-4 py-3 text-sm font-medium text-gray-900">${product.name || '-'}</td>
                        <td class="px-4 py-3 text-sm text-gray-600">${product.unit || 'UN'}</td>
                        <td class="px-4 py-3 text-sm text-gray-900">${quantity.toFixed(2)}</td>
                        <td class="px-4 py-3 text-sm text-gray-900">${formatCurrency(price)}</td>
                        <td class="px-4 py-3 text-sm font-semibold text-blue-600">${formatCurrency(total)}</td>
                    `;
                    table.appendChild(row);
                });

                // Totals
                totals.innerHTML = `
                    <div class="flex items-center justify-between">
                        <div>
                            <h5 class="font-semibold text-gray-700">📦 Resumo da Movimentação</h5>
                            <p class="text-sm text-gray-600">Total de ${movement.products.length} produto(s) diferentes</p>
                        </div>
                        <div class="text-right">
                            <div class="text-sm text-gray-600">Quantidade Total: <span class="font-medium">${Math.round(totalQuantity)}</span></div>
                            <div class="text-lg font-bold text-green-600">Valor Total: ${formatCurrency(totalValue)}</div>
                        </div>
                    </div>
                `;
            }

            openModal('movementProductsModal');
        }

        // Save movement from XML
        async function saveFromXml() {
            if (!currentXmlData) {
                showError('Nenhum XML processado');
                return;
            }

            try {
                showLoading();

                // First, check if company exists or create it
                let companyId = null;
                const existingCompany = companies.find(c => c.cnpj === currentXmlData.company.cnpj);
                
                if (existingCompany) {
                    companyId = existingCompany.id;
                } else {
                    // Create new company
                    const companyResponse = await fetch('?action=save_company', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({
                            name: currentXmlData.company.name,
                            cnpj: currentXmlData.company.cnpj,
                            phone: currentXmlData.company.phone,
                            email: currentXmlData.company.email,
                            address: currentXmlData.company.address
                        })
                    });
                    
                    const companyResult = await companyResponse.json();
                    if (companyResult.status !== 'success') {
                        throw new Error('Erro ao criar empresa: ' + companyResult.message);
                    }
                    
                    // Reload companies
                    await loadData();
                    companyId = companies.find(c => c.cnpj === currentXmlData.company.cnpj)?.id;
                }

                // Create movement
                const movementData = {
                    companyId: companyId,
                    type: 'entrada', // Default to entrada for XML imports
                    date: currentXmlData.movement.date,
                    nfe: currentXmlData.movement.nfe,
                    products: currentXmlData.products,
                    xml_path: currentXmlData.movement.xml_path
                };

                const response = await fetch('?action=save_movement', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(movementData)
                });

                const result = await response.json();

                if (result.status === 'success') {
                    showSuccess('Movimentação salva com sucesso!');
                    closeModal('xmlModal');
                    loadData();
                } else {
                    throw new Error(result.message || 'Erro ao salvar movimentação');
                }
            } catch (error) {
                showError('Erro ao salvar: ' + error.message);
            } finally {
                hideLoading();
            }
        }

        // Open movement modal
        function openMovementModal(type = '', movementId = null) {
            const modal = document.getElementById('movementModal');
            const title = document.getElementById('movementModalTitle');
            
            // Reset form
            document.getElementById('movementForm').reset();
            document.getElementById('movementId').value = movementId || '';
            document.getElementById('productsList').innerHTML = '';
            document.getElementById('imagePreview').classList.add('hidden');
            document.getElementById('imagePath').value = '';
            currentImagePath = null;

            if (movementId) {
                // Edit mode
                const movement = movements.find(m => m.id === movementId);
                if (movement) {
                    title.textContent = 'Editar Movimentação';
                    document.getElementById('movementCompany').value = movement.company_id;
                    document.getElementById('movementType').value = movement.type;
                    document.getElementById('movementDate').value = movement.date;
                    document.getElementById('movementNfe').value = movement.nfe || '';
                    document.getElementById('movementNotes').value = movement.notes || '';
                    
                    if (movement.image_path) {
                        showImagePreview(movement.image_path);
                    }

                    // Load products
                    if (movement.products && Array.isArray(movement.products)) {
                        movement.products.forEach(product => {
                            addProductRow(product);
                        });
                    }
                }
            } else {
                // New mode
                title.textContent = type ? `Nova ${type.charAt(0).toUpperCase() + type.slice(1)}` : 'Nova Movimentação';
                if (type) {
                    document.getElementById('movementType').value = type;
                }
                setDefaultDate();
                addProductRow(); // Add one empty product row
            }

            openModal('movementModal');
        }

        // Add product row
        function addProductRow(product = null) {
            const container = document.getElementById('productsList');
            const index = container.children.length;
            
            const div = document.createElement('div');
            div.className = 'grid grid-cols-1 md:grid-cols-5 gap-2 mb-2 p-3 border border-gray-200 rounded-lg';
            div.innerHTML = `
                <div>
                    <input type="text" placeholder="Nome do produto" required
                           value="${product?.name || ''}"
                           class="w-full border border-gray-300 rounded px-2 py-1 text-sm focus:ring-1 focus:ring-blue-500">
                </div>
                <div>
                    <input type="number" placeholder="Quantidade" required step="0.01" min="0"
                           value="${product?.quantity || ''}"
                           class="w-full border border-gray-300 rounded px-2 py-1 text-sm focus:ring-1 focus:ring-blue-500">
                </div>
                <div>
                    <input type="number" placeholder="Preço unitário" required step="0.01" min="0"
                           value="${product?.price || ''}"
                           class="w-full border border-gray-300 rounded px-2 py-1 text-sm focus:ring-1 focus:ring-blue-500">
                </div>
                <div>
                    <input type="text" placeholder="Total" readonly
                           value="${product?.total ? formatCurrency(product.total) : ''}"
                           class="w-full border border-gray-300 rounded px-2 py-1 text-sm bg-gray-50">
                </div>
                <div>
                    <button type="button" onclick="removeProductRow(this)" class="bg-red-500 hover:bg-red-600 text-white px-2 py-1 rounded text-sm">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            `;

            // Add event listeners for calculation
            const qtyInput = div.querySelector('input[placeholder="Quantidade"]');
            const priceInput = div.querySelector('input[placeholder="Preço unitário"]');
            const totalInput = div.querySelector('input[placeholder="Total"]');

            function calculateTotal() {
                const qty = parseFloat(qtyInput.value) || 0;
                const price = parseFloat(priceInput.value) || 0;
                const total = qty * price;
                totalInput.value = formatCurrency(total);
            }

            qtyInput.addEventListener('input', calculateTotal);
            priceInput.addEventListener('input', calculateTotal);

            // Calculate initial total if product data exists
            if (product) {
                calculateTotal();
            }

            container.appendChild(div);
        }

        // Remove product row
        function removeProductRow(button) {
            const container = document.getElementById('productsList');
            if (container.children.length > 1) {
                button.closest('.grid').remove();
            } else {
                showError('Pelo menos um produto deve ser mantido');
            }
        }

        // Upload image
        async function uploadImage() {
            const fileInput = document.getElementById('movementImage');
            const file = fileInput.files[0];

            if (!file) {
                showError('Selecione uma imagem');
                return;
            }

            try {
                showLoading();
                const formData = new FormData();
                formData.append('imagefile', file);

                const response = await fetch('?action=upload_image', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.status === 'success') {
                    currentImagePath = result.path;
                    document.getElementById('imagePath').value = result.path;
                    showImagePreview(result.path);
                    showSuccess('Imagem enviada com sucesso!');
                } else {
                    throw new Error(result.message || 'Erro ao enviar imagem');
                }
            } catch (error) {
                showError('Erro ao enviar imagem: ' + error.message);
            } finally {
                hideLoading();
            }
        }

        // Show image preview
        function showImagePreview(imagePath) {
            const preview = document.getElementById('imagePreview');
            const img = document.getElementById('previewImg');
            img.src = imagePath;
            preview.classList.remove('hidden');
        }

        // Save movement
        async function saveMovement() {
            try {
                // Validate form
                const companyId = document.getElementById('movementCompany').value;
                const type = document.getElementById('movementType').value;
                const date = document.getElementById('movementDate').value;

                if (!companyId || !type || !date) {
                    showError('Preencha todos os campos obrigatórios');
                    return;
                }

                // Get products
                const productRows = document.querySelectorAll('#productsList .grid');
                const products = [];

                for (let row of productRows) {
                    const inputs = row.querySelectorAll('input');
                    const name = inputs[0].value.trim();
                    const quantity = parseFloat(inputs[1].value) || 0;
                    const price = parseFloat(inputs[2].value) || 0;

                    if (!name || quantity <= 0 || price < 0) {
                        showError('Todos os produtos devem ter nome, quantidade maior que zero e preço válido');
                        return;
                    }

                    products.push({
                        name: name,
                        quantity: quantity,
                        price: price,
                        total: quantity * price
                    });
                }

                if (products.length === 0) {
                    showError('Adicione pelo menos um produto');
                    return;
                }

                showLoading();

                const movementData = {
                    id: document.getElementById('movementId').value || null,
                    companyId: companyId,
                    type: type,
                    date: date,
                    nfe: document.getElementById('movementNfe').value.trim(),
                    products: products,
                    image_path: document.getElementById('imagePath').value || null,
                    notes: document.getElementById('movementNotes').value.trim()
                };

                const response = await fetch('?action=save_movement', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(movementData)
                });

                const result = await response.json();

                if (result.status === 'success') {
                    showSuccess('Movimentação salva com sucesso!');
                    closeModal('movementModal');
                    loadData();
                } else {
                    throw new Error(result.message || 'Erro ao salvar movimentação');
                }
            } catch (error) {
                showError('Erro ao salvar: ' + error.message);
            } finally {
                hideLoading();
            }
        }

        // Edit movement
        function editMovement(movementId) {
            openMovementModal('', movementId);
        }

        // Delete movement
        async function deleteMovement(movementId) {
            if (!confirm('Tem certeza que deseja excluir esta movimentação?')) {
                return;
            }

            try {
                showLoading();
                const response = await fetch('?action=delete_movement', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({id: movementId})
                });

                const result = await response.json();

                if (result.status === 'success') {
                    showSuccess('Movimentação excluída com sucesso!');
                    loadData();
                } else {
                    throw new Error(result.message || 'Erro ao excluir movimentação');
                }
            } catch (error) {
                showError('Erro ao excluir: ' + error.message);
            } finally {
                hideLoading();
            }
        }

        // Open company modal
        function openCompanyModal(companyId = null) {
            const modal = document.getElementById('companyModal');
            const title = document.getElementById('companyModalTitle');
            
            // Reset form
            document.getElementById('companyForm').reset();
            document.getElementById('companyId').value = companyId || '';

            if (companyId) {
                // Edit mode
                const company = companies.find(c => c.id === companyId);
                if (company) {
                    title.textContent = 'Editar Empresa';
                    document.getElementById('companyName').value = company.name;
                    document.getElementById('companyCnpj').value = company.cnpj || '';
                    document.getElementById('companyPhone').value = company.phone || '';
                    document.getElementById('companyEmail').value = company.email || '';
                    document.getElementById('companyAddress').value = company.address || '';
                }
            } else {
                // New mode
                title.textContent = 'Nova Empresa';
            }

            openModal('companyModal');
        }

        // Save company
        async function saveCompany() {
            try {
                const name = document.getElementById('companyName').value.trim();
                
                if (!name) {
                    showError('Nome da empresa é obrigatório');
                    return;
                }

                showLoading();

                const companyData = {
                    id: document.getElementById('companyId').value || null,
                    name: name,
                    cnpj: document.getElementById('companyCnpj').value.trim(),
                    phone: document.getElementById('companyPhone').value.trim(),
                    email: document.getElementById('companyEmail').value.trim(),
                    address: document.getElementById('companyAddress').value.trim()
                };

                const response = await fetch('?action=save_company', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(companyData)
                });

                const result = await response.json();

                if (result.status === 'success') {
                    showSuccess('Empresa salva com sucesso!');
                    closeModal('companyModal');
                    loadData();
                } else {
                    throw new Error(result.message || 'Erro ao salvar empresa');
                }
            } catch (error) {
                showError('Erro ao salvar: ' + error.message);
            } finally {
                hideLoading();
            }
        }

        // Edit company
        function editCompany(companyId) {
            openCompanyModal(companyId);
        }

        // Delete company
        async function deleteCompany(companyId) {
            if (!confirm('Tem certeza que deseja excluir esta empresa? Esta ação não pode ser desfeita.')) {
                return;
            }

            try {
                showLoading();
                const response = await fetch('?action=delete_company', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({id: companyId})
                });

                const result = await response.json();

                if (result.status === 'success') {
                    showSuccess('Empresa excluída com sucesso!');
                    loadData();
                } else {
                    throw new Error(result.message || 'Erro ao excluir empresa');
                }
            } catch (error) {
                showError('Erro ao excluir: ' + error.message);
            } finally {
                hideLoading();
            }
        }

        // Export data
        async function exportData() {
            try {
                showLoading();
                const response = await fetch('?action=export_data');
                
                if (response.ok) {
                    const blob = await response.blob();
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `gestao_estoque_${new Date().toISOString().split('T')[0]}.json`;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    window.URL.revokeObjectURL(url);
                    showSuccess('Dados exportados com sucesso!');
                } else {
                    throw new Error('Erro ao exportar dados');
                }
            } catch (error) {
                showError('Erro ao exportar: ' + error.message);
            } finally {
                hideLoading();
            }
        }

        // Utility functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.remove('hidden');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
            
            // Reset specific modal states
            if (modalId === 'xmlModal') {
                document.getElementById('xmlForm').reset();
                document.getElementById('xmlPreview').classList.add('hidden');
                document.getElementById('saveFromXmlBtn').classList.add('hidden');
                currentXmlData = null;
            }
        }

        function showLoading() {
            document.getElementById('loadingOverlay').classList.remove('hidden');
        }

        function hideLoading() {
            document.getElementById('loadingOverlay').classList.add('hidden');
        }

        function showError(message) {
            alert('Erro: ' + message);
        }

        function showSuccess(message) {
            alert('Sucesso: ' + message);
        }

        function formatCurrency(value) {
            const num = parseFloat(value) || 0;
            return new Intl.NumberFormat('pt-BR', {
                style: 'currency',
                currency: 'BRL'
            }).format(num);
        }

        function formatDate(dateString) {
            if (!dateString) return '';
            const date = new Date(dateString + 'T00:00:00');
            return date.toLocaleDateString('pt-BR');
        }

        function formatCnpj(cnpj) {
            if (!cnpj) return '';
            const cleanCnpj = cnpj.replace(/\D/g, '');
            if (cleanCnpj.length === 14) {
                return cleanCnpj.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5');
            }
            return cnpj;
        }

        // Close modals when clicking outside
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal-backdrop')) {
                const modals = ['xmlModal', 'movementModal', 'companiesModal', 'companyModal'];
                modals.forEach(modalId => {
                    if (!document.getElementById(modalId).classList.contains('hidden')) {
                        closeModal(modalId);
                    }
                });
            }
        });
    </script>
</body>
</html>
