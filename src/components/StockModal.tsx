
import React from 'react';
import { StockItem } from '../types';
import Modal from './Modal';

interface StockModalProps {
  isOpen: boolean;
  onClose: () => void;
  stockItems: StockItem[];
  summary: {
    total_products: number;
    total_quantity: number;
    total_value: number;
  };
}

export default function StockModal({ isOpen, onClose, stockItems, summary }: StockModalProps) {
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
      title="Saldo de Estoque Detalhado"
      size="6xl"
    >
      <div className="space-y-4">
        <div className="bg-blue-50 p-4 rounded-lg">
          <div className="grid grid-cols-3 gap-4 text-center">
            <div>
              <span className="text-sm text-gray-600">Total de Produtos</span>
              <p className="text-2xl font-bold text-blue-600">{summary.total_products}</p>
            </div>
            <div>
              <span className="text-sm text-gray-600">Quantidade Total</span>
              <p className="text-2xl font-bold text-green-600">{Math.round(summary.total_quantity)}</p>
            </div>
            <div>
              <span className="text-sm text-gray-600">Valor Total</span>
              <p className="text-2xl font-bold text-purple-600">{formatCurrency(summary.total_value)}</p>
            </div>
          </div>
        </div>

        <div className="table-container max-h-96 overflow-y-auto">
          {stockItems.length === 0 ? (
            <div className="p-6 text-center text-gray-500">
              <i className="fas fa-inbox text-3xl mb-2 block"></i>
              Nenhum produto em estoque
            </div>
          ) : (
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50 sticky top-0">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Código</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Produto</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Quantidade</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Preço Médio</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Valor Total</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Empresa</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {stockItems.map((item, index) => (
                  <tr key={`${item.code}-${item.name}`} className="hover:bg-gray-50">
                    <td className="px-6 py-4 text-sm text-gray-900">{item.code || '-'}</td>
                    <td className="px-6 py-4 text-sm font-medium text-gray-900">{item.name}</td>
                    <td className="px-6 py-4 text-sm text-gray-900">{Math.round(item.quantity)}</td>
                    <td className="px-6 py-4 text-sm text-gray-900">{formatCurrency(item.avg_price)}</td>
                    <td className="px-6 py-4 text-sm font-medium text-gray-900">{formatCurrency(item.total_value)}</td>
                    <td className="px-6 py-4 text-sm text-gray-500">{item.company}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </div>
    </Modal>
  );
}
