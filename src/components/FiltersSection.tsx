
import React from 'react';
import { Company, Filters } from '../types';

interface FiltersSectionProps {
  companies: Company[];
  filters: Filters;
  onFiltersChange: (filters: Filters) => void;
}

export default function FiltersSection({ companies, filters, onFiltersChange }: FiltersSectionProps) {
  const handleFilterChange = (key: keyof Filters, value: any) => {
    onFiltersChange({ ...filters, [key]: value });
  };

  const clearFilters = () => {
    onFiltersChange({});
  };

  const hasActiveFilters = Object.values(filters).some(value => value !== undefined && value !== '');

  return (
    <div className="mb-6">
      <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-4 sm:p-6">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between mb-4 gap-3">
          <h3 className="text-lg font-semibold text-gray-800 flex items-center">
            <i className="fas fa-filter text-gray-600 mr-2"></i>
            Filtros
          </h3>
          {hasActiveFilters && (
            <button
              onClick={clearFilters}
              className="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1.5 rounded-lg text-sm font-medium transition-colors flex items-center self-start sm:self-auto"
            >
              <i className="fas fa-times mr-2"></i>
              Limpar Filtros
            </button>
          )}
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
          {/* Company Filter */}
          <div className="space-y-2">
            <label className="block text-sm font-medium text-gray-700">Empresa</label>
            <select
              value={filters.company_filter || ''}
              onChange={(e) => handleFilterChange('company_filter', e.target.value || undefined)}
              className="w-full px-3 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
            >
              <option value="">Todas as empresas</option>
              {companies.map(company => (
                <option key={company.id} value={company.id}>
                  {company.name}
                </option>
              ))}
            </select>
          </div>

          {/* Type Filter */}
          <div className="space-y-2">
            <label className="block text-sm font-medium text-gray-700">Tipo</label>
            <select
              value={filters.type_filter || ''}
              onChange={(e) => handleFilterChange('type_filter', e.target.value || undefined)}
              className="w-full px-3 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
            >
              <option value="">Todos os tipos</option>
              <option value="entrada">Entrada</option>
              <option value="saida">Saída</option>
              <option value="devolucao">Devolução</option>
            </select>
          </div>

          {/* Date Range */}
          <div className="space-y-2">
            <label className="block text-sm font-medium text-gray-700">Data Inicial</label>
            <input
              type="date"
              value={filters.start_date || ''}
              onChange={(e) => handleFilterChange('start_date', e.target.value || undefined)}
              className="w-full px-3 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
            />
          </div>

          <div className="space-y-2">
            <label className="block text-sm font-medium text-gray-700">Data Final</label>
            <input
              type="date"
              value={filters.end_date || ''}
              onChange={(e) => handleFilterChange('end_date', e.target.value || undefined)}
              className="w-full px-3 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
            />
          </div>

          {/* Value Range */}
          <div className="space-y-2">
            <label className="block text-sm font-medium text-gray-700">Valor Mínimo</label>
            <input
              type="number"
              step="0.01"
              placeholder="R$ 0,00"
              value={filters.min_value || ''}
              onChange={(e) => handleFilterChange('min_value', e.target.value ? parseFloat(e.target.value) : undefined)}
              className="w-full px-3 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
            />
          </div>

          <div className="space-y-2">
            <label className="block text-sm font-medium text-gray-700">Valor Máximo</label>
            <input
              type="number"
              step="0.01"
              placeholder="R$ 0,00"
              value={filters.max_value || ''}
              onChange={(e) => handleFilterChange('max_value', e.target.value ? parseFloat(e.target.value) : undefined)}
              className="w-full px-3 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
            />
          </div>

          {/* Product Search */}
          <div className="space-y-2 sm:col-span-2">
            <label className="block text-sm font-medium text-gray-700">Buscar Produto</label>
            <div className="relative">
              <input
                type="text"
                placeholder="Digite o nome do produto..."
                value={filters.product_filter || ''}
                onChange={(e) => handleFilterChange('product_filter', e.target.value || undefined)}
                className="w-full pl-10 pr-3 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
              />
              <i className="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
            </div>
          </div>
        </div>

        {/* Active Filters Display */}
        {hasActiveFilters && (
          <div className="mt-4 flex flex-wrap gap-2">
            {filters.company_filter && (
              <span className="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                {companies.find(c => c.id === filters.company_filter)?.name}
                <button
                  onClick={() => handleFilterChange('company_filter', undefined)}
                  className="ml-2 text-blue-600 hover:text-blue-800"
                >
                  <i className="fas fa-times"></i>
                </button>
              </span>
            )}
            {filters.type_filter && (
              <span className="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                {filters.type_filter}
                <button
                  onClick={() => handleFilterChange('type_filter', undefined)}
                  className="ml-2 text-purple-600 hover:text-purple-800"
                >
                  <i className="fas fa-times"></i>
                </button>
              </span>
            )}
            {filters.product_filter && (
              <span className="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                "{filters.product_filter}"
                <button
                  onClick={() => handleFilterChange('product_filter', undefined)}
                  className="ml-2 text-green-600 hover:text-green-800"
                >
                  <i className="fas fa-times"></i>
                </button>
              </span>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
