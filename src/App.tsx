
import React, { useState, useEffect } from 'react';
import { v4 as uuidv4 } from 'uuid';
import { User, Company, Movement, Filters, StockItem, XMLData } from './types';
import { useLocalStorage } from './hooks/useLocalStorage';
import Login from './components/Login';
import Header from './components/Header';
import Modal from './components/Modal';
import CompanyModal from './components/CompanyModal';
import MovementModal from './components/MovementModal';
import XMLImportModal from './components/XMLImportModal';
import StockModal from './components/StockModal';
import ProductsModal from './components/ProductsModal';
import FiltersSection from './components/FiltersSection';

function App() {
  const [user, setUser] = useLocalStorage<User | null>('user', null);
  const [companies, setCompanies] = useLocalStorage<Company[]>('companies', []);
  const [movements, setMovements] = useLocalStorage<Movement[]>('movements', []);
  
  // Modal states
  const [showCompanyModal, setShowCompanyModal] = useState(false);
  const [showMovementModal, setShowMovementModal] = useState(false);
  const [showCompaniesModal, setShowCompaniesModal] = useState(false);
  const [showXMLModal, setShowXMLModal] = useState(false);
  const [showStockModal, setShowStockModal] = useState(false);
  const [showProductsModal, setShowProductsModal] = useState(false);
  
  const [editingCompany, setEditingCompany] = useState<Company | undefined>();
  const [editingMovement, setEditingMovement] = useState<Movement | undefined>();
  const [selectedMovement, setSelectedMovement] = useState<Movement | undefined>();
  const [movementType, setMovementType] = useState<'entrada' | 'saida' | 'devolucao'>('entrada');
  const [filters, setFilters] = useState<Filters>({});
  const [filteredMovements, setFilteredMovements] = useState<Movement[]>([]);

  useEffect(() => {
    applyFilters();
  }, [movements, filters]);

  const applyFilters = () => {
    let filtered = movements;

    if (filters.company_filter) {
      filtered = filtered.filter(m => m.company_id === filters.company_filter);
    }

    if (filters.type_filter) {
      filtered = filtered.filter(m => m.type === filters.type_filter);
    }

    if (filters.start_date) {
      filtered = filtered.filter(m => m.date >= filters.start_date!);
    }

    if (filters.end_date) {
      filtered = filtered.filter(m => m.date <= filters.end_date!);
    }

    if (filters.min_value !== undefined) {
      filtered = filtered.filter(m => m.total_value >= filters.min_value!);
    }

    if (filters.max_value !== undefined) {
      filtered = filtered.filter(m => m.total_value <= filters.max_value!);
    }

    if (filters.product_filter) {
      filtered = filtered.filter(m => 
        m.products.some(p => 
          p.name.toLowerCase().includes(filters.product_filter!.toLowerCase())
        )
      );
    }

    setFilteredMovements(filtered);
  };

  const handleLogin = (userData: User) => {
    setUser(userData);
  };

  const handleLogout = () => {
    setUser(null);
  };

  const handleSaveCompany = (companyData: Omit<Company, 'id' | 'created_at'>) => {
    if (editingCompany) {
      setCompanies(companies.map(c => 
        c.id === editingCompany.id 
          ? { ...c, ...companyData }
          : c
      ));
    } else {
      const newCompany: Company = {
        ...companyData,
        id: uuidv4(),
        created_at: new Date().toISOString()
      };
      setCompanies([...companies, newCompany]);
    }
    setEditingCompany(undefined);
    setShowCompanyModal(false);
  };

  const handleSaveMovement = (movementData: Omit<Movement, 'id' | 'created_at'>) => {
    if (editingMovement) {
      setMovements(movements.map(m => 
        m.id === editingMovement.id 
          ? { ...m, ...movementData }
          : m
      ));
    } else {
      const newMovement: Movement = {
        ...movementData,
        id: uuidv4(),
        created_at: new Date().toISOString()
      };
      setMovements([...movements, newMovement]);
    }
    setEditingMovement(undefined);
    setShowMovementModal(false);
  };

  const handleDeleteCompany = (id: string) => {
    const hasMovements = movements.some(m => m.company_id === id);
    if (hasMovements) {
      alert('Não é possível excluir empresa com movimentações. Exclua as movimentações primeiro.');
      return;
    }
    
    if (confirm('Tem certeza que deseja excluir esta empresa?')) {
      setCompanies(companies.filter(c => c.id !== id));
    }
  };

  const handleDeleteMovement = (id: string) => {
    if (confirm('Tem certeza que deseja excluir esta movimentação?')) {
      setMovements(movements.filter(m => m.id !== id));
    }
  };

  const handleXMLImport = (xmlData: XMLData) => {
    let company = companies.find(c => c.cnpj === xmlData.company.cnpj);
    
    if (!company) {
      company = {
        id: uuidv4(),
        name: xmlData.company.name,
        cnpj: xmlData.company.cnpj,
        phone: xmlData.company.phone,
        email: xmlData.company.email,
        address: xmlData.company.address,
        created_at: new Date().toISOString()
      };
      setCompanies([...companies, company]);
    }

    const newMovement: Movement = {
      id: uuidv4(),
      company_id: company.id,
      type: 'entrada',
      date: xmlData.movement.date,
      nfe: xmlData.movement.nfe,
      products: xmlData.products.map(p => ({
        id: uuidv4(),
        name: p.name,
        code: p.code || '',
        unit: p.unit || 'UN',
        quantity: p.quantity,
        unitValue: p.price,
        totalValue: p.total
      })),
      total_value: xmlData.movement.total_value,
      xml_path: xmlData.movement.xml_path,
      created_at: new Date().toISOString()
    };

    setMovements([...movements, newMovement]);
    setShowXMLModal(false);
  };

  const calculateStockBalance = (): StockItem[] => {
    const stockMap = new Map<string, StockItem>();

    const movementsToProcess = filters.company_filter 
      ? movements.filter(m => m.company_id === filters.company_filter)
      : movements;

    movementsToProcess.forEach(movement => {
      const company = companies.find(c => c.id === movement.company_id);
      const multiplier = movement.type === 'entrada' || movement.type === 'devolucao' ? 1 : -1;

      movement.products.forEach(product => {
        const key = `${product.code}|${product.name}`;
        
        if (!stockMap.has(key)) {
          stockMap.set(key, {
            code: product.code || '',
            name: product.name,
            quantity: 0,
            avg_price: product.unitValue,
            total_value: 0,
            company: company?.name || 'N/A'
          });
        }

        const stockItem = stockMap.get(key)!;
        const newQuantity = stockItem.quantity + (product.quantity * multiplier);
        
        if (multiplier > 0 && product.quantity > 0) {
          const currentTotal = stockItem.avg_price * stockItem.quantity;
          const newTotal = currentTotal + product.totalValue;
          const totalItems = newQuantity;
          
          if (totalItems > 0) {
            stockItem.avg_price = newTotal / totalItems;
          }
        }

        stockItem.quantity = newQuantity;
        stockItem.total_value = stockItem.quantity * stockItem.avg_price;
      });
    });

    return Array.from(stockMap.values())
      .filter(item => item.quantity > 0)
      .sort((a, b) => a.name.localeCompare(b.name));
  };

  const exportData = () => {
    const exportData = {
      export_date: new Date().toISOString(),
      companies,
      movements
    };

    const blob = new Blob([JSON.stringify(exportData, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `gestao_estoque_${new Date().toISOString().split('T')[0]}.json`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  };

  const formatDate = (dateString: string) => {
    return new Date(dateString + 'T00:00:00').toLocaleDateString('pt-BR');
  };

  const formatCnpj = (cnpj?: string) => {
    if (!cnpj) return '';
    const cleanCnpj = cnpj.replace(/\D/g, '');
    if (cleanCnpj.length === 14) {
      return cleanCnpj.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5');
    }
    return cnpj;
  };

  const formatCurrency = (value: number) => {
    return new Intl.NumberFormat('pt-BR', {
      style: 'currency',
      currency: 'BRL'
    }).format(value);
  };

  const getTypeColor = (type: string) => {
    switch (type) {
      case 'entrada': return 'bg-emerald-50 text-emerald-700 border border-emerald-200';
      case 'saida': return 'bg-amber-50 text-amber-700 border border-amber-200';
      case 'devolucao': return 'bg-violet-50 text-violet-700 border border-violet-200';
      default: return 'bg-gray-50 text-gray-700 border border-gray-200';
    }
  };

  const getTypeIcon = (type: string) => {
    switch (type) {
      case 'entrada': return 'fa-arrow-up';
      case 'saida': return 'fa-arrow-down';
      case 'devolucao': return 'fa-undo';
      default: return 'fa-circle';
    }
  };

  const openNewMovement = (type: 'entrada' | 'saida' | 'devolucao') => {
    setMovementType(type);
    setEditingMovement(undefined);
    setShowMovementModal(true);
  };

  const openEditMovement = (movement: Movement) => {
    setEditingMovement(movement);
    setShowMovementModal(true);
  };

  const openEditCompany = (company: Company) => {
    setEditingCompany(company);
    setShowCompanyModal(true);
  };

  const viewMovementProducts = (movement: Movement) => {
    setSelectedMovement(movement);
    setShowProductsModal(true);
  };

  const stockBalance = calculateStockBalance();
  const stockSummary = {
    total_products: stockBalance.length,
    total_quantity: stockBalance.reduce((sum, item) => sum + item.quantity, 0),
    total_value: stockBalance.reduce((sum, item) => sum + item.total_value, 0)
  };

  const movementsWithCompanies = filteredMovements.map(movement => ({
    ...movement,
    company: companies.find(c => c.id === movement.company_id)
  }));

  if (!user?.isAuthenticated) {
    return <Login onLogin={handleLogin} />;
  }

  return (
    <div className="min-h-screen bg-gradient-to-br from-slate-50 to-slate-100">
      <Header 
        user={user} 
        onLogout={handleLogout}
        onExport={exportData}
        onManageCompanies={() => setShowCompaniesModal(true)}
      />

      <main className="max-w-7xl mx-auto px-3 sm:px-4 lg:px-6 py-4 sm:py-6">
        {/* Quick Actions - Mobile First Design */}
        <div className="mb-6">
          <h2 className="text-lg font-semibold text-gray-800 mb-4 flex items-center">
            <i className="fas fa-bolt text-blue-500 mr-2"></i>
            Ações Rápidas
          </h2>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <button
              onClick={() => setShowXMLModal(true)}
              className="group relative bg-white hover:bg-blue-50 border border-blue-200 rounded-xl p-4 flex flex-col items-center justify-center text-center transition-all duration-200 hover:shadow-md hover:-translate-y-1"
            >
              <div className="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center mb-3 group-hover:bg-blue-200 transition-colors">
                <i className="fas fa-upload text-blue-600 text-lg"></i>
              </div>
              <span className="text-sm font-medium text-gray-700">Importar XML</span>
              <span className="text-xs text-gray-500 mt-1">Upload de nota fiscal</span>
            </button>

            <button
              onClick={() => openNewMovement('entrada')}
              className="group relative bg-white hover:bg-emerald-50 border border-emerald-200 rounded-xl p-4 flex flex-col items-center justify-center text-center transition-all duration-200 hover:shadow-md hover:-translate-y-1"
            >
              <div className="w-12 h-12 bg-emerald-100 rounded-full flex items-center justify-center mb-3 group-hover:bg-emerald-200 transition-colors">
                <i className="fas fa-plus text-emerald-600 text-lg"></i>
              </div>
              <span className="text-sm font-medium text-gray-700">Nova Entrada</span>
              <span className="text-xs text-gray-500 mt-1">Adicionar produtos</span>
            </button>

            <button
              onClick={() => openNewMovement('saida')}
              className="group relative bg-white hover:bg-amber-50 border border-amber-200 rounded-xl p-4 flex flex-col items-center justify-center text-center transition-all duration-200 hover:shadow-md hover:-translate-y-1"
            >
              <div className="w-12 h-12 bg-amber-100 rounded-full flex items-center justify-center mb-3 group-hover:bg-amber-200 transition-colors">
                <i className="fas fa-minus text-amber-600 text-lg"></i>
              </div>
              <span className="text-sm font-medium text-gray-700">Nova Saída</span>
              <span className="text-xs text-gray-500 mt-1">Retirar produtos</span>
            </button>

            <button
              onClick={() => openNewMovement('devolucao')}
              className="group relative bg-white hover:bg-violet-50 border border-violet-200 rounded-xl p-4 flex flex-col items-center justify-center text-center transition-all duration-200 hover:shadow-md hover:-translate-y-1"
            >
              <div className="w-12 h-12 bg-violet-100 rounded-full flex items-center justify-center mb-3 group-hover:bg-violet-200 transition-colors">
                <i className="fas fa-undo text-violet-600 text-lg"></i>
              </div>
              <span className="text-sm font-medium text-gray-700">Devolução</span>
              <span className="text-xs text-gray-500 mt-1">Devolver itens</span>
            </button>
          </div>
        </div>

        {/* Dashboard Cards - Mobile First Grid */}
        <div className="mb-6">
          <h2 className="text-lg font-semibold text-gray-800 mb-4 flex items-center">
            <i className="fas fa-chart-bar text-blue-500 mr-2"></i>
            Resumo Geral
          </h2>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4">
            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5 hover:shadow-md transition-shadow">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm font-medium text-gray-600 mb-1">Entradas</p>
                  <p className="text-2xl font-bold text-gray-900">
                    {filteredMovements.filter(m => m.type === 'entrada').length}
                  </p>
                </div>
                <div className="w-10 h-10 bg-emerald-100 rounded-lg flex items-center justify-center">
                  <i className="fas fa-arrow-up text-emerald-600"></i>
                </div>
              </div>
            </div>
            
            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5 hover:shadow-md transition-shadow">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm font-medium text-gray-600 mb-1">Saídas</p>
                  <p className="text-2xl font-bold text-gray-900">
                    {filteredMovements.filter(m => m.type === 'saida').length}
                  </p>
                </div>
                <div className="w-10 h-10 bg-amber-100 rounded-lg flex items-center justify-center">
                  <i className="fas fa-arrow-down text-amber-600"></i>
                </div>
              </div>
            </div>

            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5 hover:shadow-md transition-shadow">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm font-medium text-gray-600 mb-1">Devoluções</p>
                  <p className="text-2xl font-bold text-gray-900">
                    {filteredMovements.filter(m => m.type === 'devolucao').length}
                  </p>
                </div>
                <div className="w-10 h-10 bg-violet-100 rounded-lg flex items-center justify-center">
                  <i className="fas fa-undo text-violet-600"></i>
                </div>
              </div>
            </div>

            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5 hover:shadow-md transition-shadow">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm font-medium text-gray-600 mb-1">Valor Total</p>
                  <p className="text-xl font-bold text-gray-900">
                    {formatCurrency(filteredMovements.reduce((sum, m) => sum + m.total_value, 0))}
                  </p>
                </div>
                <div className="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                  <i className="fas fa-chart-line text-blue-600"></i>
                </div>
              </div>
            </div>

            <button
              onClick={() => setShowStockModal(true)}
              className="bg-gradient-to-r from-indigo-50 to-purple-50 hover:from-indigo-100 hover:to-purple-100 border border-indigo-200 rounded-xl p-5 transition-all duration-200 hover:shadow-md hover:-translate-y-1"
            >
              <div className="flex items-center justify-between">
                <div className="text-left">
                  <p className="text-sm font-medium text-gray-600 mb-1 flex items-center">
                    Estoque
                    <i className="fas fa-external-link-alt text-xs ml-2"></i>
                  </p>
                  <p className="text-lg font-bold text-gray-900">
                    {stockSummary.total_products} produtos
                  </p>
                  <p className="text-sm text-gray-600">{formatCurrency(stockSummary.total_value)}</p>
                </div>
                <div className="w-10 h-10 bg-indigo-100 rounded-lg flex items-center justify-center">
                  <i className="fas fa-warehouse text-indigo-600"></i>
                </div>
              </div>
            </button>
          </div>
        </div>

        {/* Filters */}
        <FiltersSection 
          companies={companies}
          filters={filters}
          onFiltersChange={setFilters}
        />

        {/* Movements Table */}
        <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
          <div className="px-4 sm:px-6 py-4 border-b border-gray-100 bg-gray-50">
            <h3 className="text-lg font-semibold text-gray-800 flex items-center">
              <i className="fas fa-list text-gray-600 mr-2"></i>
              Movimentações Recentes
            </h3>
          </div>
          
          <div className="overflow-x-auto">
            {movementsWithCompanies.length === 0 ? (
              <div className="p-8 text-center">
                <div className="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                  <i className="fas fa-inbox text-2xl text-gray-400"></i>
                </div>
                <h4 className="text-lg font-medium text-gray-600 mb-2">Nenhuma movimentação</h4>
                <p className="text-gray-500">Comece importando um XML ou criando uma nova movimentação</p>
              </div>
            ) : (
              <div className="max-h-96 overflow-y-auto">
                <table className="w-full">
                  <thead className="bg-gray-50 sticky top-0">
                    <tr>
                      <th className="px-4 sm:px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wide">Data</th>
                      <th className="px-4 sm:px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wide">Empresa</th>
                      <th className="px-4 sm:px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wide">Tipo</th>
                      <th className="px-4 sm:px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wide hidden sm:table-cell">NFe</th>
                      <th className="px-4 sm:px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wide">Produtos</th>
                      <th className="px-4 sm:px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wide">Valor</th>
                      <th className="px-4 sm:px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wide hidden md:table-cell">Anexos</th>
                      <th className="px-4 sm:px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wide">Ações</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-100">
                    {movementsWithCompanies.map((movement) => (
                      <tr key={movement.id} className="hover:bg-gray-50 transition-colors">
                        <td className="px-4 sm:px-6 py-4 text-sm text-gray-900 font-medium">
                          {formatDate(movement.date)}
                        </td>
                        <td className="px-4 sm:px-6 py-4 text-sm text-gray-700 max-w-xs truncate">
                          {movement.company?.name || 'N/A'}
                        </td>
                        <td className="px-4 sm:px-6 py-4">
                          <span className={`inline-flex px-3 py-1 text-xs font-medium rounded-full ${getTypeColor(movement.type)}`}>
                            <i className={`fas ${getTypeIcon(movement.type)} mr-1`}></i>
                            {movement.type.charAt(0).toUpperCase() + movement.type.slice(1)}
                          </span>
                        </td>
                        <td className="px-4 sm:px-6 py-4 text-sm text-gray-600 hidden sm:table-cell">
                          {movement.nfe || '-'}
                        </td>
                        <td className="px-4 sm:px-6 py-4 text-sm max-w-xs">
                          <button
                            onClick={() => viewMovementProducts(movement)}
                            className="text-left hover:text-blue-600 transition-colors group flex items-center"
                            title="Ver todos os produtos"
                          >
                            <span className="truncate">
                              {movement.products.length > 0 
                                ? `${movement.products[0].name}${movement.products.length > 1 ? ` +${movement.products.length - 1}` : ''}`
                                : 'N/A'
                              }
                            </span>
                            {movement.products.length > 0 && (
                              <i className="fas fa-external-link-alt text-xs ml-2 opacity-0 group-hover:opacity-100 transition-opacity"></i>
                            )}
                          </button>
                        </td>
                        <td className="px-4 sm:px-6 py-4 text-sm font-semibold text-gray-900">
                          {formatCurrency(movement.total_value)}
                        </td>
                        <td className="px-4 sm:px-6 py-4 text-center hidden md:table-cell">
                          <div className="flex justify-center space-x-1">
                            {movement.image_path && (
                              <div className="w-6 h-6 bg-blue-100 rounded-full flex items-center justify-center">
                                <i className="fas fa-image text-blue-600 text-xs"></i>
                              </div>
                            )}
                            {movement.xml_path && (
                              <div className="w-6 h-6 bg-green-100 rounded-full flex items-center justify-center">
                                <i className="fas fa-file-code text-green-600 text-xs"></i>
                              </div>
                            )}
                            {!movement.image_path && !movement.xml_path && (
                              <span className="text-gray-400 text-xs">-</span>
                            )}
                          </div>
                        </td>
                        <td className="px-4 sm:px-6 py-4">
                          <div className="flex justify-end space-x-2">
                            <button
                              onClick={() => openEditMovement(movement)}
                              className="w-8 h-8 bg-blue-100 hover:bg-blue-200 rounded-lg flex items-center justify-center transition-colors"
                              title="Editar"
                            >
                              <i className="fas fa-edit text-blue-600 text-sm"></i>
                            </button>
                            <button
                              onClick={() => handleDeleteMovement(movement.id)}
                              className="w-8 h-8 bg-red-100 hover:bg-red-200 rounded-lg flex items-center justify-center transition-colors"
                              title="Excluir"
                            >
                              <i className="fas fa-trash text-red-600 text-sm"></i>
                            </button>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>
      </main>

      {/* Modals */}
      <CompanyModal
        isOpen={showCompanyModal}
        onClose={() => {
          setShowCompanyModal(false);
          setEditingCompany(undefined);
        }}
        onSave={handleSaveCompany}
        company={editingCompany}
      />

      <MovementModal
        isOpen={showMovementModal}
        onClose={() => {
          setShowMovementModal(false);
          setEditingMovement(undefined);
        }}
        onSave={handleSaveMovement}
        companies={companies}
        movement={editingMovement}
        defaultType={movementType}
        stockItems={stockBalance}
      />

      <XMLImportModal
        isOpen={showXMLModal}
        onClose={() => setShowXMLModal(false)}
        onImport={handleXMLImport}
      />

      <StockModal
        isOpen={showStockModal}
        onClose={() => setShowStockModal(false)}
        stockItems={stockBalance}
        summary={stockSummary}
      />

      {selectedMovement && (
        <ProductsModal
          isOpen={showProductsModal}
          onClose={() => {
            setShowProductsModal(false);
            setSelectedMovement(undefined);
          }}
          movement={selectedMovement}
          company={companies.find(c => c.id === selectedMovement.company_id)}
        />
      )}

      {/* Companies Management Modal */}
      <Modal
        isOpen={showCompaniesModal}
        onClose={() => setShowCompaniesModal(false)}
        title="Gerenciar Empresas"
        size="6xl"
      >
        <div className="mb-6">
          <button
            onClick={() => {
              setEditingCompany(undefined);
              setShowCompanyModal(true);
            }}
            className="bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white px-4 py-2.5 rounded-lg flex items-center font-medium transition-all duration-200 hover:shadow-md"
          >
            <i className="fas fa-plus mr-2"></i>Nova Empresa
          </button>
        </div>

        <div className="bg-white rounded-lg border border-gray-200 overflow-hidden">
          {companies.length === 0 ? (
            <div className="p-8 text-center">
              <div className="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i className="fas fa-building text-2xl text-gray-400"></i>
              </div>
              <h4 className="text-lg font-medium text-gray-600 mb-2">Nenhuma empresa cadastrada</h4>
              <p className="text-gray-500">Adicione sua primeira empresa para começar</p>
            </div>
          ) : (
            <div className="overflow-x-auto max-h-96 overflow-y-auto">
              <table className="w-full">
                <thead className="bg-gray-50 sticky top-0">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Nome</th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase hidden sm:table-cell">CNPJ</th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase hidden md:table-cell">Telefone</th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase hidden lg:table-cell">Email</th>
                    <th className="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Ações</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {companies.map((company) => (
                    <tr key={company.id} className="hover:bg-gray-50 transition-colors">
                      <td className="px-6 py-4 text-sm font-medium text-gray-900">
                        {company.name}
                      </td>
                      <td className="px-6 py-4 text-sm text-gray-600 hidden sm:table-cell">
                        {formatCnpj(company.cnpj)}
                      </td>
                      <td className="px-6 py-4 text-sm text-gray-600 hidden md:table-cell">
                        {company.phone || '-'}
                      </td>
                      <td className="px-6 py-4 text-sm text-gray-600 hidden lg:table-cell">
                        {company.email || '-'}
                      </td>
                      <td className="px-6 py-4">
                        <div className="flex justify-end space-x-2">
                          <button
                            onClick={() => openEditCompany(company)}
                            className="w-8 h-8 bg-blue-100 hover:bg-blue-200 rounded-lg flex items-center justify-center transition-colors"
                            title="Editar"
                          >
                            <i className="fas fa-edit text-blue-600 text-sm"></i>
                          </button>
                          <button
                            onClick={() => handleDeleteCompany(company.id)}
                            className="w-8 h-8 bg-red-100 hover:bg-red-200 rounded-lg flex items-center justify-center transition-colors"
                            title="Excluir"
                          >
                            <i className="fas fa-trash text-red-600 text-sm"></i>
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </Modal>
    </div>
  );
}

export default App;
