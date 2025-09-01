
export interface User {
  id: string;
  username: string;
  isAuthenticated: boolean;
  loginTime: number;
}

export interface Company {
  id: string;
  name: string;
  phone?: string;
  cnpj?: string;
  email?: string;
  address?: string;
  created_at: string;
}

export interface Product {
  id: string;
  code?: string;
  name: string;
  unit: string;
  quantity: number;
  unitValue: number;
  totalValue: number;
}

export interface Movement {
  id: string;
  company_id: string;
  type: 'entrada' | 'saida' | 'devolucao';
  date: string;
  nfe?: string;
  products: Product[];
  total_value: number;
  image_path?: string;
  xml_path?: string;
  notes?: string;
  created_at: string;
}

export interface StockItem {
  code: string;
  name: string;
  quantity: number;
  avg_price: number;
  total_value: number;
  company: string;
}

export interface XMLData {
  company: {
    cnpj: string;
    name: string;
    phone?: string;
    email?: string;
    address?: string;
  };
  movement: {
    nfe: string;
    date: string;
    total_value: number;
    xml_path?: string;
  };
  products: Array<{
    name: string;
    quantity: number;
    price: number;
    total: number;
    unit?: string;
    code?: string;
  }>;
}

export interface Filters {
  company_filter?: string;
  type_filter?: string;
  start_date?: string;
  end_date?: string;
  min_value?: number;
  max_value?: number;
  product_filter?: string;
}
