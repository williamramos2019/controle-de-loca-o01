
import React, { useState, useEffect } from 'react';
import { Movement, Company, Product, StockItem } from '../types';
import Modal from './Modal';
import { v4 as uuidv4 } from 'uuid';

interface MovementModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSave: (movement: Omit<Movement, 'id' | 'created_at'>) => void;
  companies: Company[];
  movement?: Movement;
  defaultType?: 'entrada' | 'saida' | 'devolucao';
  stockItems: StockItem[];
}

export default function MovementModal({ 
  isOpen, 
  onClose, 
  onSave, 
  companies, 
  movement,
  defaultType = 'entrada',
  stockItems
}: MovementModalProps) {
  const [formData, setFormData] = useState({
    company_id: '',
    type: defaultType,
    date: new Date().toISOString().split('T')[0],
    nfe: '',
    notes: '',
    image_path: '',
    xml_path: ''
  });

  const [products, setProducts] = useState<Product[]>([]);
  const [showSuggestionModal, setShowSuggestionModal] = useState(false);
  const [availableProducts, setAvailableProducts] = useState<StockItem[]>([]);

  useEffect(() => {
    if (movement) {
      setFormData({
        company_id: movement.company_id,
        type: movement.type,
        date: movement.date,
        nfe: movement.nfe || '',
        notes: movement.notes || '',
        image_path: movement.image_path || '',
        xml_path: movement.xml_path || ''
      });
      setProducts(movement.products);
    } else {
      setFormData({
        company_id: '',
        type: defaultType,
        date: new Date().toISOString().split('T')[0],
        nfe: '',
        notes: '',
        image_path: '',
        xml_path: ''
      });
      setProducts([createEmptyProduct()]);
    }
  }, [movement, isOpen, defaultType]);

  const createEmptyProduct = (): Product => ({
    id: uuidv4(),
    code: '',
    name: '',
    unit: 'UN',
    quantity: 1,
    unitValue: 0,
    totalValue: 0
  });

  const addProduct = () => {
    setProducts([...products, createEmptyProduct()]);
  };

  const removeProduct = (id: string) => {
    setProducts(products.filter(p => p.id !== id));
  };

  const updateProduct = (id: string, field: keyof Product, value: any) => {
    setProducts(products.map(product => {
      if (product.id === id) {
        const updated = { ...product, [field]: value };
        if (field === 'quantity' || field === 'unitValue') {
          updated.totalValue = updated.quantity * updated.unitValue;
        }
        return updated;
      }
      return product;
    }));
  };

  const getTotalValue = () => {
    return products.reduce((total, product) => total + product.totalValue, 0);
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    
    // Filtrar produtos válidos (remover produtos vazios)
    const validProducts = products.filter(product => 
      product.name.trim() !== '' && 
      product.quantity > 0 && 
      product.unitValue >= 0
    );

    // Verificar se há pelo menos um produto válido
    if (validProducts.length === 0) {
      alert('Adicione pelo menos um produto válido à movimentação.');
      return;
    }
    
    const movementData = {
      ...formData,
      products: validProducts,
      total_value: validProducts.reduce((total, product) => total + product.totalValue, 0)
    };

    onSave(movementData);
    onClose();
  };

  const getTypeColor = (type: string) => {
    switch (type) {
      case 'entrada': return 'text-green-600';
      case 'saida': return 'text-yellow-600';
      case 'devolucao': return 'text-purple-600';
      default: return 'text-gray-600';
    }
  };

  const getAvailableProductsForReturn = () => {
    if (!formData.company_id) return;
    
    const selectedCompany = companies.find(c => c.id === formData.company_id);
    if (!selectedCompany) return;

    const companyProducts = stockItems.filter(item => 
      item.company === selectedCompany.name && item.quantity > 0
    );
    
    setAvailableProducts(companyProducts);
    setShowSuggestionModal(true);
  };

  const addSuggestedProduct = (stockItem: StockItem) => {
    const newProduct: Product = {
      id: uuidv4(),
      code: stockItem.code,
      name: stockItem.name,
      unit: 'UN',
      quantity: 1,
      unitValue: stockItem.avg_price,
      totalValue: stockItem.avg_price
    };
    
    setProducts([...products, newProduct]);
    setShowSuggestionModal(false);
  };

  const formatCurrency = (value: number) => {
    return new Intl.NumberFormat('pt-BR', {
      style: 'currency',
      currency: 'BRL'
    }).format(value);
  };

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={movement ? 'Editar Movimentação' : 'Nova Movimentação'}
      size="5xl"
    >
      <form onSubmit={handleSubmit}>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Empresa *
            </label>
            <select
              value={formData.company_id}
              onChange={(e) => setFormData({ ...formData, company_id: e.target.value })}
              required
              className="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500"
            >
              <option value="">Selecione uma empresa</option>
              {companies.map(company => (
                <option key={company.id} value={company.id}>
                  {company.name}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Tipo *
            </label>
            <select
              value={formData.type}
              onChange={(e) => setFormData({ ...formData, type: e.target.value as any })}
              className={`w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 ${getTypeColor(formData.type)}`}
            >
              <option value="entrada">Entrada</option>
              <option value="saida">Saída</option>
              <option value="devolucao">Devolução</option>
            </select>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Data *
            </label>
            <input
              type="date"
              value={formData.date}
              onChange={(e) => setFormData({ ...formData, date: e.target.value })}
              required
              className="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500"
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              NFe
            </label>
            <input
              type="text"
              value={formData.nfe}
              onChange={(e) => setFormData({ ...formData, nfe: e.target.value })}
              placeholder="Número da NFe"
              className="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500"
            />
          </div>

          <div className="md:col-span-2">
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Observações
            </label>
            <input
              type="text"
              value={formData.notes}
              onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
              placeholder="Observações sobre a movimentação"
              className="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500"
            />
          </div>
        </div>

        <div className="mb-4">
          <div className="flex justify-between items-center mb-2">
            <label className="block text-sm font-medium text-gray-700">Produtos *</label>
            <div className="flex space-x-2">
              {formData.type === 'devolucao' && formData.company_id && (
                <button
                  type="button"
                  onClick={getAvailableProductsForReturn}
                  className="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg flex items-center text-sm"
                >
                  <i className="fas fa-undo mr-2"></i>Sugerir Produtos
                </button>
              )}
              <button
                type="button"
                onClick={addProduct}
                className="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center text-sm"
              >
                <i className="fas fa-plus mr-2"></i>Adicionar Produto
              </button>
            </div>
          </div>

          <div className="space-y-3">
            {products.map((product, index) => {
              const isIncomplete = !product.name.trim() || product.quantity <= 0;
              return (
              <div key={product.id} className={`grid grid-cols-1 md:grid-cols-7 gap-2 p-3 border rounded-lg ${
                isIncomplete ? 'border-orange-300 bg-orange-50' : 'border-gray-200'
              }`}>
                <div>
                  <input
                    type="text"
                    placeholder="Código"
                    value={product.code}
                    onChange={(e) => updateProduct(product.id, 'code', e.target.value)}
                    className="w-full border border-gray-300 rounded px-2 py-1 text-sm"
                  />
                </div>
                <div className="md:col-span-2">
                  <input
                    type="text"
                    placeholder="Nome do produto"
                    value={product.name}
                    onChange={(e) => updateProduct(product.id, 'name', e.target.value)}
                    required
                    className="w-full border border-gray-300 rounded px-2 py-1 text-sm"
                  />
                </div>
                <div>
                  <select
                    value={product.unit}
                    onChange={(e) => updateProduct(product.id, 'unit', e.target.value)}
                    className="w-full border border-gray-300 rounded px-2 py-1 text-sm"
                  >
                    <option value="UN">UN</option>
                    <option value="KG">KG</option>
                    <option value="L">L</option>
                    <option value="M">M</option>
                    <option value="CX">CX</option>
                  </select>
                </div>
                <div>
                  <input
                    type="number"
                    placeholder="Qtd"
                    value={product.quantity}
                    onChange={(e) => updateProduct(product.id, 'quantity', parseFloat(e.target.value) || 0)}
                    min="0"
                    step="0.01"
                    className="w-full border border-gray-300 rounded px-2 py-1 text-sm"
                  />
                </div>
                <div>
                  <input
                    type="number"
                    placeholder="Valor Unit."
                    value={product.unitValue}
                    onChange={(e) => updateProduct(product.id, 'unitValue', parseFloat(e.target.value) || 0)}
                    min="0"
                    step="0.01"
                    className="w-full border border-gray-300 rounded px-2 py-1 text-sm"
                  />
                </div>
                <div className="flex items-center space-x-2">
                  <span className="text-sm font-medium">
                    R$ {product.totalValue.toFixed(2)}
                  </span>
                  {products.length > 1 && (
                    <button
                      type="button"
                      onClick={() => removeProduct(product.id)}
                      className="text-red-600 hover:text-red-800"
                    >
                      <i className="fas fa-trash text-sm"></i>
                    </button>
                  )}
                </div>
              </div>
            )})}
          </div>
          </div>

          <div className="mt-3 flex justify-between items-center">
            <div className="text-sm text-orange-600">
              {products.some(p => !p.name.trim() || p.quantity <= 0) && (
                <i className="fas fa-exclamation-triangle mr-1"></i>
              )}
              {products.some(p => !p.name.trim() || p.quantity <= 0) ? 
                'Produtos incompletos serão removidos automaticamente' : 
                ''
              }
            </div>
            <span className="text-lg font-semibold">
              Total: {formatCurrency(getTotalValue())}
            </span>
          </div>
        </div>

        <div className="flex justify-end space-x-2">
          <button
            type="button"
            onClick={onClose}
            className="px-4 py-2 text-gray-700 bg-gray-200 rounded-lg hover:bg-gray-300"
          >
            Cancelar
          </button>
          <button
            type="submit"
            className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
          >
            <i className="fas fa-save mr-2"></i>Salvar Movimentação
          </button>
        </div>
      </form>

      {/* Suggestion Modal */}
      <Modal
        isOpen={showSuggestionModal}
        onClose={() => setShowSuggestionModal(false)}
        title="Produtos Disponíveis para Devolução"
        size="4xl"
      >
        <div className="space-y-4">
          <p className="text-gray-600 mb-4">
            Selecione os produtos que estão em estoque para sugerir como devolução:
          </p>
          
          {availableProducts.length === 0 ? (
            <div className="p-6 text-center text-gray-500">
              <i className="fas fa-inbox text-3xl mb-2 block"></i>
              Nenhum produto em estoque para esta empresa
            </div>
          ) : (
            <div className="max-h-96 overflow-y-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50 sticky top-0">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Código</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Produto</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estoque</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Preço Médio</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Ação</th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {availableProducts.map((item, index) => (
                    <tr key={`${item.code}-${item.name}`} className="hover:bg-gray-50">
                      <td className="px-4 py-3 text-sm text-gray-900">{item.code || '-'}</td>
                      <td className="px-4 py-3 text-sm font-medium text-gray-900">{item.name}</td>
                      <td className="px-4 py-3 text-sm text-gray-900">{Math.round(item.quantity)}</td>
                      <td className="px-4 py-3 text-sm text-gray-900">{formatCurrency(item.avg_price)}</td>
                      <td className="px-4 py-3 text-sm">
                        <button
                          type="button"
                          onClick={() => addSuggestedProduct(item)}
                          className="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-sm"
                        >
                          <i className="fas fa-plus mr-1"></i>Adicionar
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          
          <div className="flex justify-end mt-4">
            <button
              type="button"
              onClick={() => setShowSuggestionModal(false)}
              className="px-4 py-2 text-gray-700 bg-gray-200 rounded-lg hover:bg-gray-300"
            >
              Fechar
            </button>
          </div>
        </div>
      </Modal>
    </Modal>
  );
}
