#!/usr/bin/env python3
"""
MotoDean Sales ML Processor - XGBoost Edition
Processes sales_dataset-final.csv using XGBoost Regressor
to predict next month's product demand and generate restock recommendations.
"""

import pandas as pd
import numpy as np
import json
import os
import warnings
from datetime import datetime
warnings.filterwarnings('ignore')

from xgboost import XGBRegressor
from sklearn.model_selection import train_test_split
from sklearn.metrics import mean_squared_error, mean_absolute_error, r2_score, accuracy_score, precision_score, recall_score, f1_score

# Set random seed for reproducibility
np.random.seed(42)

# Configuration
DATA_PATH = os.path.join(os.path.dirname(__file__), 'sales_dataset-final.csv')
OUTPUT_DIR = os.path.join(os.path.dirname(__file__), 'output')

def load_and_prepare_data():
    """Load and prepare the sales dataset for XGBoost"""
    print("Loading dataset...")
    
    if not os.path.exists(DATA_PATH):
        raise FileNotFoundError(f"Dataset not found at {DATA_PATH}")
    
    df = pd.read_csv(DATA_PATH)
    print(f"Loaded dataset with shape: {df.shape}")
    print(f"Columns: {df.columns.tolist()}")
    
    # Standardize column names
    df.columns = [c.strip() for c in df.columns]
    
    # Find date column
    date_col = next((c for c in df.columns if 'date' in c.lower()), None)
    if date_col is None:
        if 'Date' in df.columns:
            date_col = 'Date'
        else:
            raise ValueError(f"No date column found. Columns: {df.columns.tolist()}")
    
    # Parse dates
    df[date_col] = pd.to_datetime(df[date_col], errors='coerce')
    if df[date_col].isnull().any():
        print("Warning: some dates could not be parsed; those rows will be dropped.")
        df = df.dropna(subset=[date_col])
    
    # Find product column
    product_col = next((c for c in df.columns if any(k in c.lower() for k in ['item', 'product', 'sku', 'name'])), None)
    if product_col is None:
        raise ValueError(f"No product column found. Columns: {df.columns.tolist()}")
    
    # Find quantity and amount columns
    qty_col = next((c for c in df.columns if 'qty' in c.lower() or 'quantity' in c.lower()), None)
    amount_col = next((c for c in df.columns if any(k in c.lower() for k in ['amount', 'price', 'total', 'sales'])), None)
    
    if qty_col is None:
        df['Qty'] = 1
        qty_col = 'Qty'
    
    if amount_col is None:
        df['Amount'] = 0.0
        amount_col = 'Amount'
    
    # Create year_month column
    df['year_month'] = df[date_col].dt.to_period('M').astype(str)
    
    # Sort by Item and Date for lag features
    df = df.sort_values([product_col, date_col]).reset_index(drop=True)
    
    return df, date_col, product_col, qty_col, amount_col

def create_xgboost_features(df, product_col, qty_col, amount_col):
    """Create lag and lead features for XGBoost regression"""
    print("Creating XGBoost features...")
    
    # Create lag features (previous month quantity)
    df['prev_qty'] = df.groupby(product_col)[qty_col].shift(1)
    
    # Create lead feature (next month quantity - our target)
    df['next_qty'] = df.groupby(product_col)[qty_col].shift(-1)
    
    # Encode product names as numeric
    df['Item_encoded'] = df[product_col].astype('category').cat.codes
    
    # Drop rows with missing lag or lead values
    model_df = df.dropna(subset=['prev_qty', 'next_qty']).reset_index(drop=True)
    
    print(f"After feature engineering, model rows: {model_df.shape[0]}")
    
    return model_df

def train_xgboost_model(model_df, product_col, qty_col, amount_col):
    """Train XGBoost Regressor model"""
    print("Training XGBoost model...")
    
    # Define features and target
    feature_cols = ['prev_qty', amount_col, 'Item_encoded']
    target_col = 'next_qty'
    
    X = model_df[feature_cols]
    y = model_df[target_col]
    
    # Train/Test split (80/20)
    X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=0.2, random_state=42)
    
    print(f"Training set size: {len(X_train)}")
    print(f"Test set size: {len(X_test)}")
    
    # Train XGBoost Regressor
    xgb_model = XGBRegressor(n_estimators=100, random_state=42, verbosity=0)
    xgb_model.fit(X_train, y_train)
    
    # Make predictions
    y_pred = xgb_model.predict(X_test)
    
    # Calculate regression metrics
    mse = mean_squared_error(y_test, y_pred)
    mae = mean_absolute_error(y_test, y_pred)
    r2 = r2_score(y_test, y_pred)
    rmse = np.sqrt(mse)
    
    print(f"XGBoost Regression Metrics:")
    print(f"  MSE: {mse:.3f}")
    print(f"  RMSE: {rmse:.3f}")
    print(f"  MAE: {mae:.3f}")
    print(f"  R2 Score: {r2:.3f}")
    
    # For classification metrics (restock recommendation)
    threshold = np.mean(y)
    y_test_bin = (y_test >= threshold).astype(int)
    y_pred_bin = (y_pred >= threshold).astype(int)
    
    acc = accuracy_score(y_test_bin, y_pred_bin)
    prec = precision_score(y_test_bin, y_pred_bin, zero_division=0)
    rec = recall_score(y_test_bin, y_pred_bin, zero_division=0)
    f1 = f1_score(y_test_bin, y_pred_bin, zero_division=0)
    
    print(f"Classification Metrics (Restock Recommendation):")
    print(f"  Accuracy: {acc:.3f}")
    print(f"  Precision: {prec:.3f}")
    print(f"  Recall: {rec:.3f}")
    print(f"  F1-Score: {f1:.3f}")
    
    # Prepare test results
    test_results = X_test.copy()
    test_results['Predicted_Next_Qty'] = y_pred
    test_results['Actual_Next_Qty'] = y_test.values
    test_results[product_col] = model_df.loc[X_test.index, product_col].values
    test_results['year_month'] = model_df.loc[X_test.index, 'year_month'].values
    
    metrics = {
        'XGBoost': {
            'MSE': float(mse),
            'RMSE': float(rmse),
            'MAE': float(mae),
            'R2': float(r2),
            'Accuracy': float(acc),
            'Precision': float(prec),
            'Recall': float(rec),
            'F1_Score': float(f1)
        }
    }
    
    return xgb_model, metrics, test_results

def generate_xgboost_predictions(test_results, product_col, top_n=10):
    """Generate restock recommendations from XGBoost predictions"""
    print("Generating restock recommendations...")
    
    # Sort all products by predicted quantity (descending)
    all_products = test_results[[product_col, 'year_month', 'Predicted_Next_Qty']].sort_values(
        by='Predicted_Next_Qty', ascending=False
    )
    
    # Get top products by summing predicted quantities across all months
    top_products = test_results.groupby(product_col)['Predicted_Next_Qty'].sum().sort_values(
        ascending=False
    ).head(top_n)
    
    # Get top products per month
    predicted_per_month = {}
    for ym, group in test_results.groupby('year_month'):
        top_month = group.nlargest(top_n, 'Predicted_Next_Qty')[product_col].tolist()
        predicted_per_month[ym] = top_month
    
    return all_products, top_products, predicted_per_month

def get_top_products(df, product_col, qty_col):
    """Get overall and latest month top products"""
    print("Getting top products...")
    
    # Overall top 10
    top10_overall = (
        df.groupby(product_col)[qty_col]
        .sum()
        .sort_values(ascending=False)
        .head(10)
        .reset_index()
    )
    
    # Latest month top 10
    latest_month = df['year_month'].max()
    top10_latest = (
        df[df['year_month'] == latest_month]
        .groupby(product_col)[qty_col]
        .sum()
        .sort_values(ascending=False)
        .head(10)
        .reset_index()
    )
    
    return top10_overall, top10_latest, latest_month

def save_xgboost_results(metrics, top_products, all_products, predicted_per_month, top10_overall, top10_latest, latest_month, product_col, qty_col):
    """Save XGBoost results to JSON file for PHP consumption"""
    print("Saving results...")
    
    # Create output directory
    os.makedirs(OUTPUT_DIR, exist_ok=True)
    
    # Convert top products to dictionary format
    top_products_dict = {}
    for idx, (product, qty) in enumerate(top_products.items(), 1):
        top_products_dict[product] = float(qty)
    
    # Prepare results
    results = {
        'timestamp': datetime.now().isoformat(),
        'model_type': 'XGBoost',
        'model_accuracies': metrics,
        'predicted_top10_per_month': predicted_per_month,
        'top_products_predicted': top_products_dict,
        'top10_overall': top10_overall[product_col].tolist(),
        'top10_overall_qty': top10_overall[qty_col].tolist(),
        'top10_latest': top10_latest[product_col].tolist(),
        'top10_latest_qty': top10_latest[qty_col].tolist(),
        'latest_month': latest_month,
        'restock_threshold': 'Predicted Quantity >= Mean',
        'total_predictions': len(all_products)
    }
    
    # Save to JSON
    output_file = os.path.join(OUTPUT_DIR, 'ml_results.json')
    with open(output_file, 'w') as f:
        json.dump(results, f, indent=2)
    
    print(f"Results saved to {output_file}")
    return output_file

def main():
    """Main execution function"""
    print("=" * 60)
    print("MotoDean Sales ML Processor - XGBoost Edition")
    print("=" * 60)
    
    try:
        # Load and prepare data
        df, date_col, product_col, qty_col, amount_col = load_and_prepare_data()
        
        # Create XGBoost features
        model_df = create_xgboost_features(df, product_col, qty_col, amount_col)
        
        # Train XGBoost model
        xgb_model, metrics, test_results = train_xgboost_model(model_df, product_col, qty_col, amount_col)
        
        # Generate predictions
        all_products, top_products, predicted_per_month = generate_xgboost_predictions(test_results, product_col)
        
        # Get top products from overall data
        top10_overall, top10_latest, latest_month = get_top_products(df, product_col, qty_col)
        
        # Save results
        output_file = save_xgboost_results(
            metrics,
            top_products,
            all_products,
            predicted_per_month,
            top10_overall,
            top10_latest,
            latest_month,
            product_col,
            qty_col
        )
        
        print("\n" + "=" * 60)
        print("XGBoost Processing Completed Successfully!")
        print("=" * 60)
        
        print(f"\nXGBoost Model Metrics:")
        for metric, value in metrics['XGBoost'].items():
            print(f"  {metric}: {value:.3f}")
        
        print(f"\nTop 10 Products Predicted to Need Restock (Next Month):")
        for i, (prod, qty) in enumerate(top_products.items(), 1):
            print(f"  {i}. {prod}: {qty:.1f} units predicted")
        
        print(f"\nTop 10 Overall Best-Selling Products (Historical):")
        for i, (prod, qty) in enumerate(zip(top10_overall[product_col], top10_overall[qty_col]), 1):
            print(f"  {i}. {prod}: {qty} units sold")
        
        return True
        
    except Exception as e:
        print(f"\nError: {e}")
        import traceback
        traceback.print_exc()
        return False

if __name__ == "__main__":
    success = main()
    exit(0 if success else 1)

